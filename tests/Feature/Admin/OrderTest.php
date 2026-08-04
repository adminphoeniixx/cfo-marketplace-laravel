<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Vendor;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot manage orders', function () {
    $this->get(route('admin.orders.index'))->assertRedirect(route('login'));
});

test('order index lists orders with counts and summary', function () {
    actingAsAdmin();

    Order::factory()->count(2)->create(['status' => 'processing', 'grand_total' => 1000]);
    Order::factory()->cancelled()->create(['grand_total' => 5000]);
    Order::factory()->pending()->create(['grand_total' => 500]);

    $this->get(route('admin.orders.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/orders/Index')
            ->has('orders.data', 4)
            ->where('counts.all', 4)
            ->where('counts.processing', 2)
            ->where('counts.cancelled', 1)
            ->where('summary.revenue', 2500)
            ->where('summary.unpaid', 1)
        );
});

test('orders can be filtered', function () {
    actingAsAdmin();

    $customer = Customer::factory()->create(['first_name' => 'Ishaan']);
    $vendor = Vendor::factory()->create();

    $order = Order::factory()->create([
        'number' => '#5001',
        'customer_id' => $customer->id,
        'placed_at' => now()->subDays(2),
    ]);
    OrderItem::factory()->forOrder($order)->create(['vendor_id' => $vendor->id]);

    Order::factory()->pending()->create(['number' => '#5002', 'placed_at' => now()->subDays(20)]);

    $this->get(route('admin.orders.index', ['search' => '5001']))
        ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1));

    $this->get(route('admin.orders.index', ['search' => 'ishaan']))
        ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1));

    $this->get(route('admin.orders.index', ['status' => 'pending']))
        ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1));

    $this->get(route('admin.orders.index', ['payment_status' => 'paid']))
        ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1));

    $this->get(route('admin.orders.index', ['vendor' => $vendor->id]))
        ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1));

    $this->get(route('admin.orders.index', ['from' => now()->subDays(5)->toDateString()]))
        ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1));
});

test('the order screen groups totals by vendor', function () {
    actingAsAdmin();

    $vendor = Vendor::factory()->create(['name' => 'Kraft Studio']);
    $order = Order::factory()->create();

    OrderItem::factory()->forOrder($order)->create([
        'vendor_id' => $vendor->id,
        'total' => 1000,
        'commission_amount' => 100,
        'vendor_earning' => 900,
    ]);
    OrderItem::factory()->forOrder($order)->create(['vendor_id' => null, 'total' => 500]);

    $this->get(route('admin.orders.show', $order))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/orders/Show')
            ->where('vendorBreakdown.Kraft Studio.items', 1)
            ->where('vendorBreakdown.Kraft Studio.total', 1000)
            ->where('vendorBreakdown.Kraft Studio.earning', 900)
            ->where('vendorBreakdown.Store.items', 1)
        );
});

test('order status changes are timestamped and logged', function () {
    actingAsAdmin();

    $order = Order::factory()->create(['status' => 'processing']);

    $this->from(route('admin.orders.show', $order))
        ->patch(route('admin.orders.status', $order), ['status' => 'shipped', 'note' => 'Handed to courier'])
        ->assertSessionHas('success');

    expect($order->fresh())
        ->status->toBe('shipped')
        ->shipped_at->not->toBeNull();

    $this->assertDatabaseHas('order_events', [
        'order_id' => $order->id,
        'type' => 'status',
        'title' => 'Status changed from processing to shipped',
        'body' => 'Handed to courier',
    ]);
});

test('order status must be a known status', function () {
    actingAsAdmin();

    $order = Order::factory()->create();

    $this->patch(route('admin.orders.status', $order), ['status' => 'teleported'])
        ->assertSessionHasErrors('status');
});

test('marking an order paid stamps paid_at once', function () {
    actingAsAdmin();

    $order = Order::factory()->pending()->create();

    $this->patch(route('admin.orders.payment', $order), [
        'payment_status' => 'paid',
        'transaction_id' => 'TXN-9001',
    ])->assertSessionHas('success');

    $paidAt = $order->fresh()->paid_at;

    expect($paidAt)->not->toBeNull()
        ->and($order->fresh()->transaction_id)->toBe('TXN-9001');

    $this->patch(route('admin.orders.payment', $order), ['payment_status' => 'partially_refunded']);

    expect($order->fresh()->paid_at->timestamp)->toBe($paidAt->timestamp);
});

test('fulfilling every item marks the order shipped', function () {
    actingAsAdmin();

    $order = Order::factory()->create(['status' => 'processing', 'fulfillment_status' => 'unfulfilled']);
    $first = OrderItem::factory()->forOrder($order)->create(['quantity' => 2]);
    $second = OrderItem::factory()->forOrder($order)->create(['quantity' => 1]);

    $this->from(route('admin.orders.show', $order))
        ->post(route('admin.orders.fulfill', $order), [
            'items' => [
                ['id' => $first->id, 'quantity' => 2],
                ['id' => $second->id, 'quantity' => 1],
            ],
            'tracking_number' => 'TRK-123',
            'carrier' => 'Delhivery',
        ])
        ->assertSessionHas('success');

    expect($order->fresh())
        ->fulfillment_status->toBe('fulfilled')
        ->status->toBe('shipped')
        ->tracking_number->toBe('TRK-123')
        ->and($first->fresh()->fulfillment_status)->toBe('fulfilled');
});

test('fulfilling part of an order marks it partially fulfilled', function () {
    actingAsAdmin();

    $order = Order::factory()->create(['status' => 'processing']);
    $item = OrderItem::factory()->forOrder($order)->create(['quantity' => 4]);

    $this->post(route('admin.orders.fulfill', $order), [
        'items' => [['id' => $item->id, 'quantity' => 1]],
    ])->assertSessionHas('success');

    expect($order->fresh())
        ->fulfillment_status->toBe('partially_fulfilled')
        ->status->toBe('processing')
        ->and($item->fresh()->fulfillment_status)->toBe('partially_fulfilled');
});

test('fulfilment never exceeds the quantity left after cancellations', function () {
    actingAsAdmin();

    $order = Order::factory()->create(['status' => 'processing']);
    $item = OrderItem::factory()->forOrder($order)->create(['quantity' => 5, 'quantity_cancelled' => 2]);

    $this->post(route('admin.orders.fulfill', $order), [
        'items' => [['id' => $item->id, 'quantity' => 99]],
    ])->assertSessionHas('success');

    expect($item->fresh())
        ->quantity_fulfilled->toBe(3)
        ->fulfillment_status->toBe('fulfilled');
});

test('fulfilment requires at least one item', function () {
    actingAsAdmin();

    $order = Order::factory()->create();

    $this->post(route('admin.orders.fulfill', $order), ['items' => []])
        ->assertSessionHasErrors('items');
});

test('notes are appended to the order timeline', function () {
    actingAsAdmin();

    $order = Order::factory()->create();

    $this->from(route('admin.orders.show', $order))
        ->post(route('admin.orders.notes', $order), ['body' => 'Customer asked for gift wrap'])
        ->assertSessionHas('success');

    $this->assertDatabaseHas('order_events', [
        'order_id' => $order->id,
        'type' => 'note',
        'body' => 'Customer asked for gift wrap',
    ]);

    $this->post(route('admin.orders.notes', $order), ['body' => ''])
        ->assertSessionHasErrors('body');
});

test('shipping details can be edited', function () {
    actingAsAdmin();

    $order = Order::factory()->create();

    $this->put(route('admin.orders.update', $order), [
        'admin_note' => 'Priority customer',
        'shipping_method' => 'Express',
        'tracking_number' => 'TRK-777',
        'carrier' => 'BlueDart',
    ])->assertSessionHas('success');

    expect($order->fresh())
        ->admin_note->toBe('Priority customer')
        ->shipping_method->toBe('Express')
        ->tracking_number->toBe('TRK-777');
});
