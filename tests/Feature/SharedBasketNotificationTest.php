<?php

use App\Models\Cancellation;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\CancellationRequested;
use App\Notifications\OrderPlaced;
use App\Notifications\OrderStatusChanged;
use App\Notifications\RefundRequested;
use App\Services\Notifier;
use Illuminate\Support\Facades\Notification;

/**
 * A basket can carry lines from two sellers. Reading one store off the order's
 * first line told whichever seller happened to be first and quietly dropped
 * the other — and, worse, showed them the buyer's whole basket total.
 */

/**
 * An order with one line for each of two stores.
 *
 * @return array{0: Order, 1: Vendor, 2: User, 3: Vendor, 4: User}
 */
function sharedOrder(): array
{
    [$sellerA, $storeA] = actingAsSeller();

    $storeB = Vendor::factory()->create(['status' => 'approved']);
    $sellerB = User::factory()->create([
        'role' => 'vendor',
        'vendor_id' => $storeB->id,
        'is_active' => true,
    ]);

    $order = orderForStore($storeA);
    $order->items()->create([
        'vendor_id' => $storeB->id,
        'name' => 'B item',
        'sku' => 'SKU-B-'.fake()->unique()->numberBetween(1, 99999),
        'unit_price' => 500,
        'quantity' => 1,
        'tax_amount' => 90,
        'total' => 500,
        'commission_rate' => 10,
        'commission_amount' => 50,
        'vendor_earning' => 450,
    ]);

    return [$order->load('items', 'customer'), $storeA, $sellerA, $storeB, $sellerB];
}

test('both sellers on a shared basket are in the audience', function () {
    [$order, $storeA, $sellerA, $storeB, $sellerB] = sharedOrder();

    $recipients = Notifier::recipients(new OrderPlaced($order));

    expect($recipients->contains('id', $sellerA->id))->toBeTrue()
        ->and($recipients->contains('id', $sellerB->id))->toBeTrue();
});

test('each seller is told their own share, never the buyer\'s total', function () {
    [$order, $storeA, $sellerA, $storeB, $sellerB] = sharedOrder();

    $forA = new OrderPlaced($order, $storeA->id);
    $forB = new OrderPlaced($order, $storeB->id);
    $forStaff = new OrderPlaced($order);

    // A holds one ₹2000 line, B one ₹500 line.
    expect($forA->body())->toContain('2,000.00')
        ->and($forA->body())->not->toContain('500.00')
        ->and($forB->body())->toContain('500.00')
        ->and($forB->body())->not->toContain('2,000.00')
        // Staff see the basket as the buyer paid for it.
        ->and($forStaff->body())->toContain(number_format((float) $order->grand_total, 2));
});

test('an order raised in the panel reaches both stores with their own numbers', function () {
    Notification::fake();
    [$order, $storeA, $sellerA, $storeB, $sellerB] = sharedOrder();
    $admin = adminUser();

    Notifier::sendPerStore(
        (new OrderPlaced($order))->vendorIds(),
        fn (?int $storeId) => new OrderPlaced($order, $storeId),
        $admin,
    );

    Notification::assertSentTo($sellerA, OrderPlaced::class,
        fn (OrderPlaced $n) => str_contains($n->body(), '2,000.00'));
    Notification::assertSentTo($sellerB, OrderPlaced::class,
        fn (OrderPlaced $n) => str_contains($n->body(), '500.00'));
});

test('staff get one copy of a shared order, not one per store', function () {
    Notification::fake();
    [$order] = sharedOrder();
    $watcher = adminUser();

    Notifier::sendPerStore(
        (new OrderPlaced($order))->vendorIds(),
        fn (?int $storeId) => new OrderPlaced($order, $storeId),
    );

    Notification::assertSentToTimes($watcher, OrderPlaced::class, 1);
});

test('cancelling a shared order tells both sellers to stop packing', function () {
    [$order, $storeA, $sellerA, $storeB, $sellerB] = sharedOrder();

    $recipients = Notifier::recipients(new OrderStatusChanged($order));

    expect($recipients->contains('id', $sellerA->id))->toBeTrue()
        ->and($recipients->contains('id', $sellerB->id))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Requests follow the lines, not the order
|--------------------------------------------------------------------------
*/

test('a cancellation on the second seller\'s line goes to that seller', function () {
    [$order, $storeA, $sellerA, $storeB, $sellerB] = sharedOrder();

    $lineB = $order->items->firstWhere('vendor_id', $storeB->id);

    $cancellation = Cancellation::factory()->create(['order_id' => $order->id, 'status' => 'pending']);
    $cancellation->items()->create(['order_item_id' => $lineB->id, 'quantity' => 1, 'amount' => 500]);

    $recipients = Notifier::recipients(new CancellationRequested($cancellation->load('items.orderItem')));

    // Before, the order's first line decided this — and that is seller A.
    expect($recipients->contains('id', $sellerB->id))->toBeTrue()
        ->and($recipients->contains('id', $sellerA->id))->toBeFalse();
});

test('a refund on the second seller\'s line goes to that seller', function () {
    [$order, $storeA, $sellerA, $storeB, $sellerB] = sharedOrder();

    $lineB = $order->items->firstWhere('vendor_id', $storeB->id);

    $refund = Refund::factory()->create(['order_id' => $order->id, 'status' => 'pending']);
    $refund->items()->create(['order_item_id' => $lineB->id, 'quantity' => 1, 'amount' => 500]);

    $recipients = Notifier::recipients(new RefundRequested($refund->load('items.orderItem')));

    expect($recipients->contains('id', $sellerB->id))->toBeTrue()
        ->and($recipients->contains('id', $sellerA->id))->toBeFalse();
});

test('a request spanning both sellers reaches both', function () {
    [$order, $storeA, $sellerA, $storeB, $sellerB] = sharedOrder();

    $cancellation = Cancellation::factory()->create(['order_id' => $order->id, 'status' => 'pending']);

    foreach ($order->items as $line) {
        $cancellation->items()->create([
            'order_item_id' => $line->id,
            'quantity' => 1,
            'amount' => 500,
        ]);
    }

    $recipients = Notifier::recipients(new CancellationRequested($cancellation->load('items.orderItem')));

    expect($recipients->contains('id', $sellerA->id))->toBeTrue()
        ->and($recipients->contains('id', $sellerB->id))->toBeTrue();
});

test('a single-store order still behaves exactly as before', function () {
    [$me, $store] = actingAsSeller();
    $order = orderForStore($store);

    $notification = new OrderPlaced($order->load('items', 'customer'));

    expect($notification->vendorIds())->toBe([$store->id])
        ->and(Notifier::recipients($notification)->contains('id', $me->id))->toBeTrue();
});
