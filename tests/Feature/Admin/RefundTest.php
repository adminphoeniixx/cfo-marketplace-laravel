<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Refund;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot manage refunds', function () {
    $this->get(route('admin.refunds.index'))->assertRedirect(route('login'));
});

test('refund index lists refunds with counts and summary', function () {
    actingAsAdmin();

    Refund::factory()->create(['total_amount' => 400]);
    Refund::factory()->approved()->create(['total_amount' => 600]);
    Refund::factory()->processed()->create(['total_amount' => 1000]);

    $this->get(route('admin.refunds.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/refunds/Index')
            ->has('refunds.data', 3)
            ->where('counts.pending', 1)
            ->where('counts.approved', 1)
            ->where('counts.processed', 1)
            ->where('summary.pending_value', 400)
            ->where('summary.processed_value', 1000)
            ->where('summary.this_month', 1000)
        );
});

test('refunds can be filtered', function () {
    actingAsAdmin();

    Refund::factory()->create(['number' => 'REF-90001', 'reason' => 'damaged', 'method' => 'upi']);
    Refund::factory()->approved()->create(['reason' => 'late_delivery', 'method' => 'original']);

    $this->get(route('admin.refunds.index', ['search' => '90001']))
        ->assertInertia(fn (Assert $page) => $page->has('refunds.data', 1));

    $this->get(route('admin.refunds.index', ['status' => 'approved']))
        ->assertInertia(fn (Assert $page) => $page->has('refunds.data', 1));

    $this->get(route('admin.refunds.index', ['reason' => 'damaged']))
        ->assertInertia(fn (Assert $page) => $page->has('refunds.data', 1));

    $this->get(route('admin.refunds.index', ['method' => 'upi']))
        ->assertInertia(fn (Assert $page) => $page->has('refunds.data', 1));
});

test('the refund create screen loads the order', function () {
    actingAsAdmin();

    $order = Order::factory()->create();
    OrderItem::factory()->forOrder($order)->create();

    $this->get(route('admin.refunds.create', ['order' => $order->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/refunds/Form')
            ->where('order.id', $order->id)
            ->has('methods')
        );
});

test('a refund totals items shipping and adjustments', function () {
    actingAsAdmin();

    $order = Order::factory()->create(['grand_total' => 10000, 'refunded_total' => 0]);
    $item = OrderItem::factory()->forOrder($order)->create([
        'quantity' => 2,
        'total' => 2000,
        'tax_amount' => 360,
    ]);

    $this->post(route('admin.refunds.store'), [
        'order_id' => $order->id,
        'reason' => 'damaged',
        'method' => 'upi',
        'shipping_amount' => 100,
        'adjustment_amount' => 50,
        'items' => [['order_item_id' => $item->id, 'quantity' => 1]],
    ])->assertSessionHas('success');

    $refund = Refund::first();

    expect((float) $refund->items_amount)->toBe(1000.0)
        ->and((float) $refund->tax_amount)->toBe(180.0)
        ->and((float) $refund->total_amount)->toBe(1150.0)
        ->and($refund->type)->toBe('partial')
        ->and($refund->status)->toBe('pending');
});

test('a refund never exceeds the refundable amount', function () {
    actingAsAdmin();

    $order = Order::factory()->create(['grand_total' => 1000, 'refunded_total' => 800]);
    $item = OrderItem::factory()->forOrder($order)->create(['quantity' => 1, 'total' => 5000]);

    $this->post(route('admin.refunds.store'), [
        'order_id' => $order->id,
        'reason' => 'other',
        'method' => 'original',
        'items' => [['order_item_id' => $item->id, 'quantity' => 1]],
    ])->assertSessionHas('success');

    expect((float) Refund::first()->total_amount)->toBe(200.0);
});

test('creating a refund validates its order reason and method', function () {
    actingAsAdmin();

    $this->post(route('admin.refunds.store'), [
        'order_id' => 4242,
        'reason' => 'vibes',
        'method' => 'crypto',
    ])->assertSessionHasErrors(['order_id', 'reason', 'method']);
});

test('a refund can be approved then processed', function () {
    actingAsAdmin();

    $customer = Customer::factory()->create(['total_spent' => 5000]);
    $product = Product::factory()->create(['stock_quantity' => 4]);
    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'grand_total' => 3000,
        'refunded_total' => 0,
        'status' => 'processing',
    ]);
    $item = OrderItem::factory()->forOrder($order)->create([
        'product_id' => $product->id,
        'quantity' => 2,
        'total' => 2000,
    ]);

    $refund = Refund::factory()->create([
        'order_id' => $order->id,
        'customer_id' => $customer->id,
        'total_amount' => 1000,
        'restock' => true,
    ]);
    $refund->items()->create(['order_item_id' => $item->id, 'quantity' => 1, 'amount' => 1000]);

    $this->patch(route('admin.refunds.approve', $refund), ['review_note' => 'Damaged on arrival'])
        ->assertSessionHas('success');

    expect($refund->fresh()->status)->toBe('approved');

    $this->patch(route('admin.refunds.process', $refund), ['transaction_reference' => 'TXN-REFUND-1'])
        ->assertSessionHas('success');

    expect($refund->fresh())
        ->status->toBe('processed')
        ->transaction_reference->toBe('TXN-REFUND-1')
        ->processed_at->not->toBeNull()
        ->and($item->fresh()->quantity_refunded)->toBe(1)
        ->and($product->fresh()->stock_quantity)->toBe(5)
        ->and((float) $order->fresh()->refunded_total)->toBe(1000.0)
        ->and($order->fresh()->payment_status)->toBe('partially_refunded')
        ->and((float) $customer->fresh()->total_spent)->toBe(4000.0);
});

test('refunding the full order total marks the order refunded', function () {
    actingAsAdmin();

    $order = Order::factory()->create(['grand_total' => 1000, 'refunded_total' => 0, 'status' => 'processing']);
    $refund = Refund::factory()->approved()->create(['order_id' => $order->id, 'total_amount' => 1000]);

    $this->patch(route('admin.refunds.process', $refund))->assertSessionHas('success');

    expect($order->fresh())
        ->payment_status->toBe('refunded')
        ->status->toBe('refunded');
});

test('only approved refunds can be processed', function () {
    actingAsAdmin();

    $pending = Refund::factory()->create();

    $this->patch(route('admin.refunds.process', $pending))->assertStatus(422);
});

test('a refund can be rejected while pending or approved', function () {
    actingAsAdmin();

    $pending = Refund::factory()->create();
    $approved = Refund::factory()->approved()->create();
    $processed = Refund::factory()->processed()->create();

    $this->patch(route('admin.refunds.reject', $pending), ['review_note' => 'Outside window'])
        ->assertSessionHas('success');
    $this->patch(route('admin.refunds.reject', $approved), ['review_note' => 'Duplicate request'])
        ->assertSessionHas('success');
    $this->patch(route('admin.refunds.reject', $processed), ['review_note' => 'Too late'])
        ->assertStatus(422);

    expect($pending->fresh()->status)->toBe('rejected')
        ->and($approved->fresh()->status)->toBe('rejected');
});

test('rejecting a refund requires a note and approving does not', function () {
    actingAsAdmin();

    $refund = Refund::factory()->create();

    $this->patch(route('admin.refunds.reject', $refund), [])->assertSessionHasErrors('review_note');

    $this->patch(route('admin.refunds.approve', $refund))->assertSessionHas('success');
});

test('an approved refund cannot be approved twice', function () {
    actingAsAdmin();

    $refund = Refund::factory()->approved()->create();

    $this->patch(route('admin.refunds.approve', $refund))->assertStatus(422);
});
