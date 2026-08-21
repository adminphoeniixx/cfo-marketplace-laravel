<?php

use App\Models\Cancellation;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\CancellationRequested;
use App\Notifications\RefundRequested;
use App\Services\Notifier;
use Illuminate\Support\Facades\Notification;

/**
 * A seller raising their own cancellation — the "this turned out not to be in
 * stock" flow. They state the case; the marketplace still decides it.
 */
function raise(Order $order, array $overrides = []): array
{
    return [
        'order_id' => $order->id,
        'reason' => 'out_of_stock',
        'note' => 'The last one was damaged in storage.',
        'items' => [[
            'order_item_id' => $order->items->first()->id,
            'quantity' => 1,
        ]],
        ...$overrides,
    ];
}

test('a seller raises a cancellation for their own line', function () {
    [$me, $store] = actingAsSeller();
    $order = orderForStore($store);
    $item = $order->items->first();

    $this->postJson(route('api.seller.cancellations.store'), raise($order))
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.requested_by', 'vendor')
        ->assertJsonPath('data.reason', 'out_of_stock')
        ->assertJsonPath('data.items.0.order_item_id', $item->id)
        ->assertJsonPath('data.items.0.quantity', 1)
        // Half of a ₹2000 two-unit line.
        ->assertJsonPath('data.total_amount', 1000);

    expect(Cancellation::count())->toBe(1);
});

test('it lands on the order timeline for the admin to read', function () {
    [$me, $store] = actingAsSeller();
    $order = orderForStore($store);

    $this->postJson(route('api.seller.cancellations.store'), raise($order))->assertCreated();

    $event = $order->events()->where('type', 'cancellation')->latest('id')->first();

    expect($event)->not->toBeNull()
        ->and($event->title)->toContain($store->name)
        ->and($event->body)->toBe('The last one was damaged in storage.')
        ->and($event->meta['source'])->toBe('seller-api');
});

test('the marketplace is told, and the seller is not told about their own request', function () {
    Notification::fake();

    [$me, $store] = actingAsSeller();
    $admin = adminUser();
    $order = orderForStore($store);

    $this->postJson(route('api.seller.cancellations.store'), raise($order))->assertCreated();

    Notification::assertSentTo($admin, CancellationRequested::class);
    Notification::assertNotSentTo($me, CancellationRequested::class);
});

test('requested_by and status cannot be talked up from the payload', function () {
    [$me, $store] = actingAsSeller();
    $order = orderForStore($store);

    $this->postJson(route('api.seller.cancellations.store'), raise($order, [
        'requested_by' => 'admin',
        'status' => 'approved',
    ]))
        ->assertCreated()
        ->assertJsonPath('data.requested_by', 'vendor')
        ->assertJsonPath('data.status', 'pending');
});

/*
|--------------------------------------------------------------------------
| Scoping
|--------------------------------------------------------------------------
*/

test('another store\'s order is a 404, not a 403', function () {
    [$me, $store] = actingAsSeller();
    $theirs = orderForStore(Vendor::factory()->create(['status' => 'approved']));

    $this->postJson(route('api.seller.cancellations.store'), raise($theirs))
        ->assertNotFound();

    expect(Cancellation::count())->toBe(0);
});

test('a line belonging to the other seller on a shared order is refused', function () {
    [$me, $store] = actingAsSeller();
    $other = Vendor::factory()->create(['status' => 'approved']);

    $order = orderForStore($store);
    $order->items()->create([
        'vendor_id' => $other->id,
        'name' => 'Their item',
        'sku' => 'SKU-OTHER-1',
        'unit_price' => 500,
        'quantity' => 1,
        'tax_amount' => 90,
        'total' => 500,
        'commission_rate' => 10,
        'commission_amount' => 50,
        'vendor_earning' => 450,
    ]);

    $theirLine = $order->items()->where('vendor_id', $other->id)->firstOrFail();

    $this->postJson(route('api.seller.cancellations.store'), raise($order, [
        'items' => [['order_item_id' => $theirLine->id, 'quantity' => 1]],
    ]))->assertJsonValidationErrorFor('items.0.order_item_id');

    expect(Cancellation::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| What can still be cancelled
|--------------------------------------------------------------------------
*/

test('more than the line holds is refused', function () {
    [$me, $store] = actingAsSeller();
    $order = orderForStore($store);

    $this->postJson(route('api.seller.cancellations.store'), raise($order, [
        'items' => [['order_item_id' => $order->items->first()->id, 'quantity' => 3]],
    ]))->assertJsonValidationErrorFor('items.0.quantity');
});

test('quantity already shipped cannot be cancelled — that is a return', function () {
    [$me, $store] = actingAsSeller();
    $order = orderForStore($store);
    $item = $order->items->first();

    // Two on the line, one already out of the door.
    $item->update(['quantity_fulfilled' => 1, 'fulfillment_status' => 'partially_fulfilled']);

    $this->postJson(route('api.seller.cancellations.store'), raise($order, [
        'items' => [['order_item_id' => $item->id, 'quantity' => 2]],
    ]))->assertJsonValidationErrorFor('items.0.quantity');

    // The one still on the shelf can go.
    $this->postJson(route('api.seller.cancellations.store'), raise($order, [
        'items' => [['order_item_id' => $item->id, 'quantity' => 1]],
    ]))->assertCreated();
});

test('a request still awaiting review blocks a second one for the same line', function () {
    [$me, $store] = actingAsSeller();
    $order = orderForStore($store);
    $item = $order->items->first();

    $this->postJson(route('api.seller.cancellations.store'), raise($order, [
        'items' => [['order_item_id' => $item->id, 'quantity' => 2]],
    ]))->assertCreated();

    $this->postJson(route('api.seller.cancellations.store'), raise($order))
        ->assertJsonValidationErrorFor('items.0.quantity');

    expect(Cancellation::count())->toBe(1);
});

test('a rejected request frees the line up again', function () {
    [$me, $store] = actingAsSeller();
    $order = orderForStore($store);
    $item = $order->items->first();

    $this->postJson(route('api.seller.cancellations.store'), raise($order, [
        'items' => [['order_item_id' => $item->id, 'quantity' => 2]],
    ]))->assertCreated();

    Cancellation::first()->update(['status' => 'rejected']);

    $this->postJson(route('api.seller.cancellations.store'), raise($order))->assertCreated();

    expect(Cancellation::count())->toBe(2);
});

test('an already cancelled order has nothing left to cancel', function () {
    [$me, $store] = actingAsSeller();
    $order = orderForStore($store);
    $order->update(['status' => 'cancelled']);

    $this->postJson(route('api.seller.cancellations.store'), raise($order))
        ->assertJsonValidationErrorFor('order_id');
});

/*
|--------------------------------------------------------------------------
| Restock and refund defaults
|--------------------------------------------------------------------------
*/

test('stock that never existed is not handed back to stock', function () {
    [$me, $store] = actingAsSeller();
    $order = orderForStore($store);

    $this->postJson(route('api.seller.cancellations.store'), raise($order))
        ->assertCreated()
        ->assertJsonPath('data.restock', false);
});

test('any other reason does restock, and the app can say otherwise', function () {
    [$me, $store] = actingAsSeller();

    $this->postJson(route('api.seller.cancellations.store'), raise(orderForStore($store), [
        'reason' => 'address_issue',
    ]))->assertCreated()->assertJsonPath('data.restock', true);

    $this->postJson(route('api.seller.cancellations.store'), raise(orderForStore($store), [
        'reason' => 'address_issue',
        'restock' => false,
    ]))->assertCreated()->assertJsonPath('data.restock', false);
});

test('a paid order that is cancelled asks for a refund by default', function () {
    [$me, $store] = actingAsSeller();

    $paid = orderForStore($store);
    expect($paid->payment_status)->toBe('paid');

    $this->postJson(route('api.seller.cancellations.store'), raise($paid))
        ->assertCreated()
        ->assertJsonPath('data.refund_requested', true);

    $unpaid = orderForStore($store);
    $unpaid->update(['payment_status' => 'pending']);

    $this->postJson(route('api.seller.cancellations.store'), raise($unpaid))
        ->assertCreated()
        ->assertJsonPath('data.refund_requested', false);
});

/*
|--------------------------------------------------------------------------
| Reading it back
|--------------------------------------------------------------------------
*/

test('the seller sees their own request in the list afterwards', function () {
    [$me, $store] = actingAsSeller();
    $order = orderForStore($store);

    $this->postJson(route('api.seller.cancellations.store'), raise($order))->assertCreated();

    $this->getJson(route('api.seller.cancellations.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.requested_by', 'vendor');
});

test('a colleague on the same store sees it too', function () {
    [$me, $store] = actingAsSeller();
    $order = orderForStore($store);

    $this->postJson(route('api.seller.cancellations.store'), raise($order))->assertCreated();

    $mate = User::factory()->create(['role' => 'vendor', 'vendor_id' => $store->id, 'is_active' => true]);

    // The guard caches the resolved user across requests inside one test, so
    // without this the "colleague" would still be the original seller.
    $this->app['auth']->forgetGuards();
    $this->withHeader('Authorization', 'Bearer '.$mate->createToken('their phone')->plainTextToken);

    $this->getJson(route('api.seller.cancellations.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('an unknown reason is refused', function () {
    [$me, $store] = actingAsSeller();

    $this->postJson(route('api.seller.cancellations.store'), raise(orderForStore($store), [
        'reason' => 'because-i-said-so',
    ]))->assertJsonValidationErrorFor('reason');
});

/*
|--------------------------------------------------------------------------
| Being told about someone else's request
|--------------------------------------------------------------------------
*/

test('a seller now hears when a request lands on their own order', function () {
    Notification::fake();

    [$me, $store] = actingAsSeller();
    $admin = adminUser();
    $order = orderForStore($store);

    // Raised by the marketplace, not by this seller.
    $cancellation = Cancellation::factory()->create([
        'order_id' => $order->id,
        'requested_by' => 'customer',
        'status' => 'pending',
    ]);
    $cancellation->items()->create([
        'order_item_id' => $order->items->first()->id,
        'quantity' => 1,
        'amount' => 1000,
    ]);

    Notifier::send(new CancellationRequested($cancellation->load('order.items')), $admin);

    // The store the order belongs to is in the audience — the app has a
    // Requests screen, so the seller has to be told it needs answering.
    Notification::assertSentTo($me, CancellationRequested::class);
});

test('a seller on another store is not told', function () {
    Notification::fake();

    [$me, $store] = actingAsSeller();
    $otherStore = Vendor::factory()->create(['status' => 'approved']);
    $stranger = User::factory()->create([
        'role' => 'vendor',
        'vendor_id' => $otherStore->id,
        'is_active' => true,
    ]);

    $order = orderForStore($store);

    $this->postJson(route('api.seller.cancellations.store'), raise($order))->assertCreated();

    Notification::assertNotSentTo($stranger, CancellationRequested::class);
});

test('refunds reach the seller the same way', function () {
    Notification::fake();

    [$me, $store] = actingAsSeller();
    $order = orderForStore($store);

    $refund = Refund::factory()->create([
        'order_id' => $order->id,
        'status' => 'pending',
    ]);
    $refund->items()->create([
        'order_item_id' => $order->items->first()->id,
        'quantity' => 1,
        'amount' => 1000,
    ]);

    Notifier::send(new RefundRequested($refund->load('order.items')), adminUser());

    Notification::assertSentTo($me, RefundRequested::class);
});
