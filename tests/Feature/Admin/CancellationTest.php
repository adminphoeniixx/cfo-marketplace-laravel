<?php

use App\Models\Cancellation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot manage cancellations', function () {
    $this->get(route('admin.cancellations.index'))->assertRedirect(route('login'));
});

test('cancellation index lists requests with counts', function () {
    actingAsAdmin();

    Cancellation::factory()->create(['total_amount' => 500]);
    Cancellation::factory()->approved()->create(['total_amount' => 1500]);
    Cancellation::factory()->rejected()->create();

    $this->get(route('admin.cancellations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/cancellations/Index')
            ->has('cancellations.data', 3)
            ->where('counts.pending', 1)
            ->where('counts.approved', 1)
            ->where('counts.rejected', 1)
            ->where('summary.pending_value', 500)
            ->where('summary.approved_value', 1500)
        );
});

test('cancellations can be filtered by status and reason', function () {
    actingAsAdmin();

    Cancellation::factory()->create(['number' => 'CAN-90001', 'reason' => 'out_of_stock']);
    Cancellation::factory()->approved()->create(['reason' => 'duplicate_order']);

    $this->get(route('admin.cancellations.index', ['search' => '90001']))
        ->assertInertia(fn (Assert $page) => $page->has('cancellations.data', 1));

    $this->get(route('admin.cancellations.index', ['status' => 'approved']))
        ->assertInertia(fn (Assert $page) => $page->has('cancellations.data', 1));

    $this->get(route('admin.cancellations.index', ['reason' => 'out_of_stock']))
        ->assertInertia(fn (Assert $page) => $page->has('cancellations.data', 1));
});

test('the create screen loads the order being cancelled', function () {
    actingAsAdmin();

    $order = Order::factory()->create();
    OrderItem::factory()->forOrder($order)->create();

    $this->get(route('admin.cancellations.create', ['order' => $order->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/cancellations/Form')
            ->where('order.id', $order->id)
            ->has('order.items', 1)
        );
});

test('a partial cancellation is created with a prorated amount', function () {
    actingAsAdmin();

    $order = Order::factory()->create();
    $item = OrderItem::factory()->forOrder($order)->create(['quantity' => 4, 'total' => 4000]);

    $this->post(route('admin.cancellations.store'), [
        'order_id' => $order->id,
        'reason' => 'ordered_by_mistake',
        'requested_by' => 'customer',
        'note' => 'Wrong size ordered',
        'items' => [['order_item_id' => $item->id, 'quantity' => 1]],
    ])->assertSessionHas('success');

    $cancellation = Cancellation::first();

    expect($cancellation)->not->toBeNull()
        ->and($cancellation->scope)->toBe('partial')
        ->and((float) $cancellation->total_amount)->toBe(1000.0)
        ->and($cancellation->items)->toHaveCount(1);

    $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'type' => 'cancellation']);
});

test('cancelling every unit marks the request as full scope', function () {
    actingAsAdmin();

    $order = Order::factory()->create();
    $item = OrderItem::factory()->forOrder($order)->create(['quantity' => 2, 'total' => 2000]);

    $this->post(route('admin.cancellations.store'), [
        'order_id' => $order->id,
        'reason' => 'out_of_stock',
        'requested_by' => 'admin',
        'items' => [['order_item_id' => $item->id, 'quantity' => 2]],
    ])->assertSessionHas('success');

    expect(Cancellation::first()->scope)->toBe('full');
});

test('creating a cancellation validates order reason and items', function () {
    actingAsAdmin();

    $this->post(route('admin.cancellations.store'), [
        'order_id' => 9999,
        'reason' => 'because',
        'requested_by' => 'ghost',
        'items' => [],
    ])->assertSessionHasErrors(['order_id', 'reason', 'requested_by', 'items']);
});

test('approving a cancellation restocks and updates the order', function () {
    actingAsAdmin();

    $product = Product::factory()->create(['stock_quantity' => 10]);
    $order = Order::factory()->create(['status' => 'processing']);
    $item = OrderItem::factory()->forOrder($order)->create([
        'product_id' => $product->id,
        'quantity' => 2,
        'total' => 2000,
    ]);

    $cancellation = Cancellation::factory()->create(['order_id' => $order->id, 'restock' => true]);
    $cancellation->items()->create(['order_item_id' => $item->id, 'quantity' => 2, 'amount' => 2000]);

    $this->from(route('admin.cancellations.show', $cancellation))
        ->patch(route('admin.cancellations.approve', $cancellation), ['review_note' => 'Approved by support'])
        ->assertSessionHas('success');

    expect($cancellation->fresh())
        ->status->toBe('approved')
        ->reviewed_at->not->toBeNull()
        ->and($cancellation->fresh()->reviewed_by)->not->toBeNull()
        ->and($product->fresh()->stock_quantity)->toBe(12)
        ->and($item->fresh()->quantity_cancelled)->toBe(2)
        ->and($order->fresh()->status)->toBe('cancelled');
});

test('a partial approval leaves the order open', function () {
    actingAsAdmin();

    $order = Order::factory()->create(['status' => 'processing']);
    $item = OrderItem::factory()->forOrder($order)->create(['quantity' => 3, 'total' => 3000]);

    $cancellation = Cancellation::factory()->create(['order_id' => $order->id]);
    $cancellation->items()->create(['order_item_id' => $item->id, 'quantity' => 1, 'amount' => 1000]);

    $this->patch(route('admin.cancellations.approve', $cancellation))
        ->assertSessionHas('success');

    expect($order->fresh()->status)->toBe('processing')
        ->and($item->fresh()->quantity_cancelled)->toBe(1);
});

test('restock can be skipped while approving', function () {
    actingAsAdmin();

    $product = Product::factory()->create(['stock_quantity' => 10]);
    $order = Order::factory()->create();
    $item = OrderItem::factory()->forOrder($order)->create(['product_id' => $product->id, 'quantity' => 1]);

    $cancellation = Cancellation::factory()->create(['order_id' => $order->id, 'restock' => true]);
    $cancellation->items()->create(['order_item_id' => $item->id, 'quantity' => 1, 'amount' => 100]);

    $this->patch(route('admin.cancellations.approve', $cancellation), ['restock' => false])
        ->assertSessionHas('success');

    expect($product->fresh()->stock_quantity)->toBe(10)
        ->and($cancellation->fresh()->restock)->toBeFalse();
});

test('a cancellation can be rejected with a note', function () {
    actingAsAdmin();

    $cancellation = Cancellation::factory()->create();

    $this->patch(route('admin.cancellations.reject', $cancellation), ['review_note' => 'Already shipped'])
        ->assertSessionHas('success');

    expect($cancellation->fresh())
        ->status->toBe('rejected')
        ->review_note->toBe('Already shipped');

    $this->assertDatabaseHas('order_events', [
        'order_id' => $cancellation->order_id,
        'type' => 'cancellation',
    ]);
});

test('rejecting requires a review note', function () {
    actingAsAdmin();

    $cancellation = Cancellation::factory()->create();

    $this->patch(route('admin.cancellations.reject', $cancellation), [])
        ->assertSessionHasErrors('review_note');
});

test('an already reviewed cancellation cannot be reviewed again', function () {
    actingAsAdmin();

    $approved = Cancellation::factory()->approved()->create();

    $this->patch(route('admin.cancellations.approve', $approved))->assertStatus(422);
    $this->patch(route('admin.cancellations.reject', $approved), ['review_note' => 'nope'])->assertStatus(422);
});
