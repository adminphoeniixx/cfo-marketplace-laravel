<?php

use App\Models\Cancellation;
use App\Models\DeliveryPartner;
use App\Models\Order;
use App\Models\Vendor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
| Telling the courier that a cancelled order is not coming.
|
| Cancelling used to cancel the order here and nowhere else: the waybill stayed
| live, the van still came, and on a cash-on-delivery parcel somebody could
| still be asked for money at a door for an order the marketplace considered
| dead. Every courier bills for that.
|
| The refusals matter as much as the successes. A courier that will not cancel
| must never undo a cancellation the shopper has already been told about — so
| every failing path below still leaves the order cancelled, and leaves a line
| on the timeline telling a human what they now have to do by hand.
*/

beforeEach(function () {
    config([
        'services.delhivery.token' => 'test-token',
        'services.delhivery.base_url' => 'https://staging-express.delhivery.com',
        'services.delhivery.pickup_name' => 'CFO Noida',
    ]);

    DeliveryPartner::where('name', 'Delhivery')->update(['driver' => 'delhivery', 'is_active' => true]);

    $this->store = Vendor::factory()->create(['status' => 'approved']);
});

/** A live booking on an order that is about to be called off. */
function liveBooking(array $attributes = []): Order
{
    $order = Order::factory()->create([
        'status' => 'processing',
        'carrier' => 'Delhivery',
        'tracking_number' => 'AWB-7788',
        'pickup_scheduled_at' => now(),
        ...$attributes,
    ]);

    $order->items()->create([
        'vendor_id' => test()->store->id, 'name' => 'Kanchipuram silk saree',
        'sku' => 'SKU-'.$order->id, 'unit_price' => 1000, 'quantity' => 1, 'total' => 1000,
    ]);

    return $order;
}

test('cancelling an order calls the booking off with the courier', function () {
    Http::fake(['*/api/p/edit' => Http::response(['status' => true])]);

    $order = liveBooking();

    $this->actingAs(actingAsAdmin())
        ->patch(route('admin.orders.status', $order->id), ['status' => 'cancelled'])
        ->assertRedirect();

    Http::assertSent(fn ($request) => $request['waybill'] === 'AWB-7788'
        && $request['cancellation'] === 'true');

    $order->refresh();

    expect($order->status)->toBe('cancelled')
        ->and($order->shipment_status)->toBe('cancelled')
        // The van is no longer coming for it.
        ->and($order->pickup_scheduled_at)->toBeNull()
        // The waybill stays: it is what the courier's own records are keyed by,
        // and somebody reconciling a bill will need it.
        ->and($order->tracking_number)->toBe('AWB-7788');
});

test('a courier that refuses leaves the order cancelled and says what to do', function () {
    Http::fake(['*/api/p/edit' => Http::response(['status' => false], 400)]);

    $order = liveBooking();

    $this->actingAs(actingAsAdmin())
        ->patch(route('admin.orders.status', $order->id), ['status' => 'cancelled'])
        ->assertRedirect();

    $order->refresh();

    expect($order->status)->toBe('cancelled')
        // Not marked dead, because it is not — somebody has to ring them.
        ->and($order->shipment_status)->toBeNull();

    expect($order->events()->where('type', 'shipment')->latest('id')->first()->body)
        ->toContain('AWB-7788');
});

test('a courier that cannot be reached does not hold up the cancellation', function () {
    Http::fake(fn () => throw new ConnectionException('Delhivery is having a morning.'));

    $order = liveBooking();

    $this->actingAs(actingAsAdmin())
        ->patch(route('admin.orders.status', $order->id), ['status' => 'cancelled'])
        ->assertRedirect();

    expect($order->fresh()->status)->toBe('cancelled');
});

test('a waybill somebody typed in by hand is left for them to cancel', function () {
    Http::fake();

    // No driver: this partner is a name, a link and a phone number.
    DeliveryPartner::where('name', 'Delhivery')->update(['driver' => null]);

    $order = liveBooking();

    $this->actingAs(actingAsAdmin())
        ->patch(route('admin.orders.status', $order->id), ['status' => 'cancelled'])
        ->assertRedirect();

    Http::assertNothingSent();

    expect($order->fresh()->events()->where('type', 'shipment')->first()->title)
        ->toBe('Cancel this booking with Delhivery by hand');
});

test('a parcel already delivered is not called off', function () {
    Http::fake();

    $order = liveBooking(['shipment_status' => 'delivered', 'delivered_at' => now()]);

    $this->actingAs(actingAsAdmin())
        ->patch(route('admin.orders.status', $order->id), ['status' => 'cancelled'])
        ->assertRedirect();

    // Asking earns a refusal that reads like a fault, so it is not asked.
    Http::assertNothingSent();
});

test('an order cancelled twice does not book two cancellations', function () {
    Http::fake(['*/api/p/edit' => Http::response(['status' => true])]);

    $order = liveBooking();
    $admin = actingAsAdmin();

    $this->actingAs($admin)->patch(route('admin.orders.status', $order->id), ['status' => 'cancelled']);
    $this->actingAs($admin)->patch(route('admin.orders.status', $order->id), ['status' => 'cancelled']);

    Http::assertSentCount(1);
});

test('a wholly approved cancellation request calls the booking off too', function () {
    Http::fake(['*/api/p/edit' => Http::response(['status' => true])]);

    $order = liveBooking();
    $item = $order->items()->first();

    $cancellation = Cancellation::create([
        'order_id' => $order->id,
        'number' => 'CAN-0001',
        'status' => 'pending',
        'reason' => 'Changed my mind.',
        'restock' => true,
    ]);

    $cancellation->items()->create([
        'order_item_id' => $item->id,
        'quantity' => $item->quantity,
    ]);

    $this->actingAs(actingAsAdmin())
        ->patch(route('admin.cancellations.approve', $cancellation->id), ['restock' => true])
        ->assertRedirect();

    Http::assertSent(fn ($request) => $request['waybill'] === 'AWB-7788');

    expect($order->fresh()->shipment_status)->toBe('cancelled');
});

test('a part-cancellation leaves the booking alone — the rest is still going', function () {
    Http::fake(['*/api/p/edit' => Http::response(['status' => true])]);

    $order = liveBooking();
    $item = $order->items()->first();
    $item->update(['quantity' => 2]);

    $order->items()->create([
        'vendor_id' => $this->store->id, 'name' => 'Brass lamp',
        'sku' => 'SKU-lamp', 'unit_price' => 500, 'quantity' => 1, 'total' => 500,
    ]);

    $cancellation = Cancellation::create([
        'order_id' => $order->id,
        'number' => 'CAN-0002',
        'status' => 'pending',
        'reason' => 'Ordered the wrong size.',
        'restock' => true,
    ]);

    $cancellation->items()->create(['order_item_id' => $item->id, 'quantity' => 1]);

    $this->actingAs(actingAsAdmin())
        ->patch(route('admin.cancellations.approve', $cancellation->id), ['restock' => true])
        ->assertRedirect();

    Http::assertNothingSent();

    expect($order->fresh()->shipment_status)->toBeNull();
});
