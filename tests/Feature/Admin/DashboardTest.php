<?php

use App\Models\Cancellation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Vendor;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot open the admin dashboard', function () {
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
});

test('admin dashboard renders with metrics', function () {
    actingAsAdmin();

    Order::factory()->create([
        'placed_at' => now()->subDay(),
        'grand_total' => 1000,
        'status' => 'processing',
    ]);

    $this->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Dashboard')
            ->where('range', 30)
            ->where('metrics.orders.value', 1)
            ->where('metrics.revenue.value', 1000)
            ->has('salesSeries', 30)
            ->has('recentOrders', 1)
        );
});

test('dashboard range filter is limited to the supported windows', function () {
    actingAsAdmin();

    $this->get(route('admin.dashboard', ['range' => 7]))
        ->assertInertia(fn (Assert $page) => $page->where('range', 7)->has('salesSeries', 7));

    $this->get(route('admin.dashboard', ['range' => 999]))
        ->assertInertia(fn (Assert $page) => $page->where('range', 30)->has('salesSeries', 30));
});

test('cancelled orders are excluded from revenue', function () {
    actingAsAdmin();

    Order::factory()->create(['placed_at' => now(), 'grand_total' => 500, 'status' => 'processing']);
    Order::factory()->cancelled()->create(['placed_at' => now(), 'grand_total' => 9999]);

    $this->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('metrics.revenue.value', 500)
            ->where('metrics.orders.value', 1)
        );
});

test('dashboard reports pending workload and low stock', function () {
    actingAsAdmin();

    // Their parent orders are already fulfilled so they don't skew the unfulfilled count.
    Cancellation::factory()->for(Order::factory()->state(['fulfillment_status' => 'fulfilled']))->create();
    Refund::factory()->for(Order::factory()->state(['fulfillment_status' => 'fulfilled']))->create();
    Vendor::factory()->pending()->create();
    Customer::factory()->create();
    Product::factory()->create(['stock_quantity' => 1, 'low_stock_threshold' => 5]);
    Product::factory()->create(['stock_quantity' => 80, 'low_stock_threshold' => 5]);

    $order = Order::factory()->create(['placed_at' => now(), 'fulfillment_status' => 'unfulfilled']);
    OrderItem::factory()->forOrder($order)->create(['name' => 'Signature Kurta', 'quantity' => 3, 'total' => 4500]);

    $this->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('pending.cancellations', 1)
            ->where('pending.refunds', 1)
            ->where('pending.vendors', 1)
            ->where('pending.unfulfilled', 1)
            ->has('lowStock', 1)
            ->where('topProducts.0.name', 'Signature Kurta')
        );
});
