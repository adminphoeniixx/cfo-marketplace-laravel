<?php

use App\Jobs\SendFcmMessage;
use App\Models\Customer;
use App\Models\CustomerDeviceToken;
use App\Models\DeviceToken;
use App\Models\Order;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\Customer\OrderProgressed;
use Illuminate\Support\Facades\Queue;

/*
| The shopper's phone.
|
| Everything here existed except the last inch: tokens were stored, the feed
| worked, and nothing ever sent. The channel refused anything that was not a
| `User`, so a shopper could register a device, switch push on, and be met with
| silence for the entire life of their order.
|
| Two audiences share one job now, so the tests that matter most are the ones
| that prove they do not share each other's tokens.
*/

beforeEach(function () {
    // The suite runs with push off, which is right for every other test.
    config(['firebase.enabled' => true, 'firebase.project_id' => 'qa-project']);
    Queue::fake();
});

function shopperWithPhone(array $attributes = []): Customer
{
    $customer = Customer::factory()->create(['status' => 'active', ...$attributes]);

    CustomerDeviceToken::remember($customer, 'tok-'.$customer->id, 'android', 'Pixel');

    return $customer;
}

function pushOrderFor(Customer $customer, array $attributes = []): Order
{
    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'status' => 'processing',
        ...$attributes,
    ]);

    $order->items()->create([
        'vendor_id' => Vendor::factory()->create()->id,
        'name' => 'Kanchipuram silk saree', 'sku' => 'SKU-1',
        'unit_price' => 1000, 'quantity' => 1, 'total' => 1000,
    ]);

    return $order->fresh();
}

test('a parcel moving reaches the phone and the feed', function () {
    $customer = shopperWithPhone();
    $order = pushOrderFor($customer);

    $order->update(['shipment_status' => 'out_for_delivery']);

    expect($customer->notifications()->count())->toBe(1);

    $row = $customer->notifications()->first();
    expect($row->data['title'])->toBe('Arriving today')
        ->and($row->data['kind'])->toBe('order')
        ->and($row->data['link'])->toContain('/track');

    Queue::assertPushed(SendFcmMessage::class, 1);
});

test('the push carries the shopper table, never the seller one', function () {
    $customer = shopperWithPhone();

    // A seller device holding the *same* id, which is exactly the collision an
    // id-only job would have delivered into.
    $vendor = Vendor::factory()->create();
    $seller = User::factory()->create(['role' => 'vendor', 'vendor_id' => $vendor->id]);
    DeviceToken::create([
        'user_id' => $seller->id, 'token' => 'seller-token',
        'token_hash' => hash('sha256', 'seller-token'), 'platform' => 'android',
    ]);

    pushOrderFor($customer)->update(['shipment_status' => 'delivered']);

    Queue::assertPushed(SendFcmMessage::class, function (SendFcmMessage $job) {
        $type = (new ReflectionProperty($job, 'deviceType'))->getValue($job);

        return $type === CustomerDeviceToken::class;
    });
});

test('every step worth telling gets its own words', function (string $to, string $title) {
    $customer = shopperWithPhone();
    pushOrderFor($customer)->update(['shipment_status' => $to]);

    expect($customer->notifications()->first()->data['title'])->toBe($title);
})->with([
    ['in_transit', 'Your order is on the way'],
    ['out_for_delivery', 'Arriving today'],
    ['delivered', 'Delivered'],
    ['undelivered', 'We could not deliver your order'],
    ['returning', 'Your order is coming back to us'],
]);

test('the steps not worth telling stay quiet', function () {
    $customer = shopperWithPhone();
    $order = pushOrderFor($customer, ['status' => 'pending']);

    // Every paid order is "processing" a second after it is placed, and a
    // parcel is "booked" before anybody has touched it.
    $order->update(['status' => 'processing']);
    $order->update(['shipment_status' => 'booked']);
    $order->update(['admin_note' => 'nothing to do with the shopper']);

    expect($customer->notifications()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('push off still writes the feed row — the record is not the alert', function () {
    $customer = shopperWithPhone(['push_enabled' => false]);

    pushOrderFor($customer)->update(['shipment_status' => 'delivered']);

    expect($customer->notifications()->count())->toBe(1);
    Queue::assertNothingPushed();
});

test('muting order updates does not mute a refund decision', function () {
    $customer = shopperWithPhone(['notify_order_updates' => false]);
    $order = pushOrderFor($customer);

    $order->update(['shipment_status' => 'delivered']);
    Queue::assertNothingPushed();

    // A decision the shopper is waiting on carries no preference, so it goes.
    $refund = \App\Models\Refund::factory()->create([
        'order_id' => $order->id, 'customer_id' => $customer->id, 'status' => 'pending',
    ]);
    $refund->update(['status' => 'approved']);

    Queue::assertPushed(SendFcmMessage::class, 1);
    expect($customer->notifications()->count())->toBe(2);
});

test('an order with no shopper behind it notifies nobody', function () {
    // A manual order raised in the panel for a walk-in.
    $order = Order::factory()->create(['customer_id' => null, 'status' => 'processing']);

    $order->update(['shipment_status' => 'delivered']);

    Queue::assertNothingPushed();
});

test('one shopper never hears about another shopper s parcel', function () {
    $mine = shopperWithPhone();
    $theirs = shopperWithPhone();

    pushOrderFor($mine)->update(['shipment_status' => 'delivered']);

    expect($mine->notifications()->count())->toBe(1)
        ->and($theirs->fresh()->notifications()->count())->toBe(0);

    Queue::assertPushed(SendFcmMessage::class, 1);
});

test('with Firebase switched off the feed still fills', function () {
    config(['firebase.enabled' => false]);

    $customer = shopperWithPhone();
    pushOrderFor($customer)->update(['shipment_status' => 'delivered']);

    expect($customer->notifications()->count())->toBe(1);
    Queue::assertNothingPushed();
});

test('the courier webhook is one of the paths that reaches the phone', function () {
    $customer = shopperWithPhone();
    $order = pushOrderFor($customer, ['carrier' => 'Delhivery', 'tracking_number' => 'AWB-1']);

    // Straight through the action the webhook and the sweep both call.
    app(\App\Actions\Shipping\ApplyTracking::class)->handle($order, [
        'status' => 'out_for_delivery',
        'events' => [],
    ]);

    expect($customer->notifications()->first()?->data['title'])->toBe('Arriving today');
    Queue::assertPushed(SendFcmMessage::class);
});

test('OrderProgressed knows which moves matter', function () {
    expect(OrderProgressed::worthTelling('shipment_status', 'delivered'))->toBeTrue()
        ->and(OrderProgressed::worthTelling('shipment_status', 'booked'))->toBeFalse()
        ->and(OrderProgressed::worthTelling('status', 'shipped'))->toBeTrue()
        ->and(OrderProgressed::worthTelling('status', 'processing'))->toBeFalse();
});
