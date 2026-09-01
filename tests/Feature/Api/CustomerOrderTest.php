<?php

use App\Models\Cancellation;
use App\Models\Customer;
use App\Models\DeliveryPartner;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Vendor;

function orderFor(Customer $customer, array $attributes = [], array $itemAttributes = []): Order
{
    $vendor = Vendor::factory()->create(['status' => 'approved']);
    $product = sellableProduct([], $vendor);

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'status' => 'processing',
        'payment_status' => 'paid',
        'fulfillment_status' => 'unfulfilled',
        'grand_total' => 1000,
        'subtotal' => 1000,
        'placed_at' => now(),
        ...$attributes,
    ]);

    $order->items()->create([
        'product_id' => $product->id,
        'vendor_id' => $vendor->id,
        'name' => $product->name,
        'sku' => $product->sku,
        'unit_price' => 1000,
        'quantity' => 1,
        'total' => 1000,
        ...$itemAttributes,
    ]);

    return $order->load('items');
}

beforeEach(function () {
    $this->customer = actingAsCustomer();
});

test('the orders list is mine and only mine', function () {
    orderFor($this->customer);
    orderFor(Customer::factory()->create());

    $this->getJson(route('api.customer.orders.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test("another shopper's order is a 404, not a peek", function () {
    $theirs = orderFor(Customer::factory()->create());

    $this->getJson(route('api.customer.orders.show', ltrim($theirs->number, '#')))->assertNotFound();
    $this->getJson(route('api.customer.orders.track', ltrim($theirs->number, '#')))->assertNotFound();
});

test('the filters match the chips on the screen', function () {
    orderFor($this->customer, ['status' => 'shipped']);
    orderFor($this->customer, ['status' => 'completed', 'delivered_at' => now()->subDay()]);
    orderFor($this->customer, ['status' => 'cancelled']);

    expect($this->getJson(route('api.customer.orders.index', ['filter' => 'open']))->json('data'))->toHaveCount(1);
    expect($this->getJson(route('api.customer.orders.index', ['filter' => 'delivered']))->json('data'))->toHaveCount(1);
    expect($this->getJson(route('api.customer.orders.index', ['filter' => 'cancelled']))->json('data'))->toHaveCount(1);
    expect($this->getJson(route('api.customer.orders.index', ['filter' => 'all']))->json('data'))->toHaveCount(3);
});

test('an order reads back with its sellers and its timeline', function () {
    $order = orderFor($this->customer);
    $order->recordEvent('status', 'Order placed');

    $this->getJson(route('api.customer.orders.show', ltrim($order->number, '#')))
        ->assertOk()
        ->assertJsonPath('data.number', $order->number)
        ->assertJsonPath('data.status_label', 'Being packed')
        ->assertJsonCount(1, 'data.sellers')
        ->assertJsonPath('data.timeline.0.title', 'Order placed')
        ->assertJsonPath('data.can_cancel', true)
        ->assertJsonPath('data.can_return', false);
});

test('tracking names the courier and its support line', function () {
    // Couriers ship with the schema too; the app reads that list.
    DeliveryPartner::updateOrCreate(
        ['code' => 'delhivery'],
        ['name' => 'Delhivery', 'support_phone' => '1800 103 6354', 'is_active' => true],
    );

    $order = orderFor($this->customer, [
        'status' => 'shipped',
        'carrier' => 'delhivery',
        'tracking_number' => 'TRK-88213',
        'shipped_at' => now(),
    ]);

    $this->getJson(route('api.customer.orders.track', ltrim($order->number, '#')))
        ->assertOk()
        ->assertJsonPath('data.carrier.name', 'Delhivery')
        ->assertJsonPath('data.carrier.support_phone', '1800 103 6354')
        ->assertJsonPath('data.tracking_number', 'TRK-88213')
        ->assertJsonCount(4, 'data.steps');
});

test('buying again fills the basket and says what it could not', function () {
    $order = orderFor($this->customer);
    $gone = $order->items->first()->product;

    $this->postJson(route('api.customer.orders.reorder', ltrim($order->number, '#')))
        ->assertOk()
        ->assertJsonPath('added', 1);

    $gone->update(['status' => 'archived']);

    $this->deleteJson(route('api.customer.cart'));

    $this->postJson(route('api.customer.orders.reorder', ltrim($order->number, '#')))
        ->assertOk()
        ->assertJsonPath('added', 0)
        ->assertJsonCount(1, 'skipped');
});

test('a whole order can be called off before it is packed', function () {
    $order = orderFor($this->customer);

    $this->postJson(route('api.customer.orders.cancellations', ltrim($order->number, '#')), [
        'reason' => 'ordered_by_mistake',
        'note' => 'Wrong colour.',
    ])->assertCreated()
        ->assertJsonPath('data.kind', 'cancellation')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.reason_label', 'Ordered by mistake')
        ->assertJsonPath('data.amount', 1000);

    $cancellation = Cancellation::firstOrFail();

    expect($cancellation->requested_by)->toBe('customer')
        ->and($cancellation->scope)->toBe('full')
        // The money was taken, so it has to come back.
        ->and($cancellation->refund_requested)->toBeTrue();
});

test('a shipped order can no longer be cancelled', function () {
    $order = orderFor($this->customer, ['status' => 'shipped', 'fulfillment_status' => 'fulfilled']);

    $this->postJson(route('api.customer.orders.cancellations', ltrim($order->number, '#')), [
        'reason' => 'ordered_by_mistake',
    ])->assertStatus(422);
});

test('a delivered order can be returned within the window', function () {
    $order = orderFor($this->customer, [
        'status' => 'completed',
        'fulfillment_status' => 'fulfilled',
        'delivered_at' => now()->subDays(2),
    ], ['quantity_fulfilled' => 1]);

    $this->postJson(route('api.customer.orders.returns', ltrim($order->number, '#')), [
        'reason' => 'damaged',
        'method' => 'original',
        'note' => 'Torn near the border.',
        'items' => [['order_item_id' => $order->items->first()->id, 'quantity' => 1]],
    ])->assertCreated()
        ->assertJsonPath('data.kind', 'refund')
        ->assertJsonPath('data.reason_label', 'Item arrived damaged');

    expect(Refund::count())->toBe(1);
});

test('the return window closes', function () {
    $order = orderFor($this->customer, [
        'status' => 'completed',
        'delivered_at' => now()->subDays(Order::RETURN_WINDOW_DAYS + 3),
    ]);

    $this->postJson(route('api.customer.orders.returns', ltrim($order->number, '#')), [
        'reason' => 'damaged',
        'method' => 'original',
        'items' => [['order_item_id' => $order->items->first()->id, 'quantity' => 1]],
    ])->assertStatus(422);
});

test('both kinds of request land in one list', function () {
    $order = orderFor($this->customer);

    $this->postJson(route('api.customer.orders.cancellations', ltrim($order->number, '#')), [
        'reason' => 'ordered_by_mistake',
    ])->assertCreated();

    $delivered = orderFor($this->customer, ['status' => 'completed', 'delivered_at' => now()->subDay()]);

    $this->postJson(route('api.customer.orders.returns', ltrim($delivered->number, '#')), [
        'reason' => 'damaged',
        'method' => 'original',
        'items' => [['order_item_id' => $delivered->items->first()->id, 'quantity' => 1]],
    ])->assertCreated();

    $kinds = collect($this->getJson(route('api.customer.requests.index'))->json('data'))->pluck('kind');

    expect($kinds)->toHaveCount(2)->toContain('cancellation')->toContain('refund');
});

test('a request can be withdrawn while nobody has answered it', function () {
    $order = orderFor($this->customer);

    $number = $this->postJson(route('api.customer.orders.cancellations', ltrim($order->number, '#')), [
        'reason' => 'ordered_by_mistake',
    ])->json('data.number');

    $this->postJson(route('api.customer.requests.withdraw', $number))->assertOk();

    expect(Cancellation::firstOrFail()->status)->toBe('withdrawn');

    // Once answered, it is no longer the shopper's to take back.
    Cancellation::query()->update(['status' => 'approved']);

    $this->postJson(route('api.customer.requests.withdraw', $number))->assertStatus(422);
});

test("another shopper's request is invisible", function () {
    $theirs = orderFor(Customer::factory()->create());
    $cancellation = Cancellation::create([
        'number' => Cancellation::nextNumber(),
        'order_id' => $theirs->id,
        'customer_id' => $theirs->customer_id,
        'reason' => 'ordered_by_mistake',
        'requested_by' => 'customer',
        'status' => 'pending',
        'scope' => 'full',
    ]);

    $this->getJson(route('api.customer.requests.show', $cancellation->number))->assertNotFound();
    $this->getJson(route('api.customer.requests.index'))->assertJsonCount(0, 'data');
});
