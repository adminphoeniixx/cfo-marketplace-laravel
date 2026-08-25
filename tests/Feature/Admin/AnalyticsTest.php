<?php

use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Build one order with a single line item, so each test can state exactly what
 * the totals should be.
 */
function soldItem(Vendor $vendor, array $item = [], array $order = []): OrderItem
{
    $placed = $order['placed_at'] ?? now()->subDay();

    $created = Order::factory()->create([
        'status' => 'processing',
        'placed_at' => $placed,
        ...$order,
    ]);

    return OrderItem::factory()->forOrder($created)->create([
        'vendor_id' => $vendor->id,
        'quantity' => 2,
        'unit_price' => 500,
        'total' => 1000,
        'tax_amount' => 180,
        'commission_amount' => 100,
        'vendor_earning' => 900,
        ...$item,
    ]);
}

test('guests cannot see analytics', function () {
    $this->get(route('admin.analytics.index'))->assertRedirect(route('login'));
});

test('analytics reports totals for the selected window', function () {
    actingAsAdmin();

    $vendor = Vendor::factory()->create();
    soldItem($vendor);
    soldItem($vendor);

    // Outside the 30 day window, so it must not be counted.
    soldItem($vendor, [], ['placed_at' => now()->subDays(120)]);

    // Cancelled orders never count towards sales.
    soldItem($vendor, [], ['status' => 'cancelled']);

    $this->get(route('admin.analytics.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/analytics/Index')
            ->where('metrics.gross_sales.value', 2000)
            ->where('metrics.orders.value', 2)
            ->where('metrics.units.value', 4)
            ->where('metrics.average_order.value', 1000)
            ->where('metrics.commission.value', 200)
            ->where('metrics.vendor_earnings.value', 1800)
            ->where('metrics.tax.value', 360)
            ->where('filters.preset', 30)
        );
});

test('a custom date range overrides the preset', function () {
    actingAsAdmin();

    $vendor = Vendor::factory()->create();
    soldItem($vendor, [], ['placed_at' => now()->subDays(200)]);
    soldItem($vendor, [], ['placed_at' => now()->subDay()]);

    $this->get(route('admin.analytics.index', [
        'from' => now()->subDays(210)->toDateString(),
        'to' => now()->subDays(190)->toDateString(),
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('metrics.gross_sales.value', 1000)
            ->where('metrics.orders.value', 1)
            ->where('filters.preset', null)
        );
});

test('analytics can be filtered to a single vendor', function () {
    actingAsAdmin();

    $mine = Vendor::factory()->create();
    $other = Vendor::factory()->create();

    soldItem($mine);
    soldItem($other);

    $this->get(route('admin.analytics.index', ['vendor' => $mine->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('metrics.gross_sales.value', 1000)
            ->has('byVendor', 1)
            ->where('byVendor.0.id', $mine->id)
            // Store-wide figures are hidden once a vendor filter is on.
            ->where('metrics.customers', null)
            ->where('returns', null)
        );
});

test('a vendor user only ever sees their own numbers', function () {
    $vendor = Vendor::factory()->create(['status' => 'approved']);
    $other = Vendor::factory()->create();

    $this->actingAs(User::factory()->create([
        'role' => 'vendor',
        'vendor_id' => $vendor->id,
        'is_active' => true,
        'email_verified_at' => now(),
    ]));

    soldItem($vendor);
    soldItem($other);

    // The marketplace panel is shut to vendors entirely; these numbers are
    // served from the seller panel, which renders the same screen.
    $this->get(route('admin.analytics.index'))->assertRedirect('/seller');

    // Asking for another vendor's figures still returns their own.
    $this->get(route('seller.analytics.index', ['vendor' => $other->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('lockedToVendor', true)
            ->where('filters.vendor', $vendor->id)
            ->where('metrics.gross_sales.value', 1000)
            ->has('vendors', 1)
        );
});

test('the reports break sales down by product, category and customer', function () {
    actingAsAdmin();

    $vendor = Vendor::factory()->create();
    $category = Category::factory()->create(['name' => 'Lighting']);
    $product = Product::factory()->for($vendor)->create(['category_id' => $category->id]);
    $customer = Customer::factory()->create(['first_name' => 'Ishaan', 'last_name' => 'Roy']);

    soldItem(
        $vendor,
        ['product_id' => $product->id, 'name' => 'Table Lamp', 'sku' => 'LAMP-1'],
        ['customer_id' => $customer->id],
    );

    $this->get(route('admin.analytics.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('topProducts.0.name', 'Table Lamp')
            ->where('topProducts.0.units', 2)
            ->where('byCategory.0.name', 'Lighting')
            ->where('topCustomers.0.email', $customer->email)
            ->where('topCustomers.0.orders_count', 1)
            ->where('statusBreakdown.processing', 1)
        );
});

test('reports can be exported as csv', function () {
    actingAsAdmin();

    $vendor = Vendor::factory()->create(['name' => 'Casa Luz']);
    soldItem($vendor, ['name' => 'Table Lamp', 'sku' => 'LAMP-1']);

    $response = $this->get(route('admin.analytics.export', ['report' => 'vendors']));

    $response->assertOk()->assertHeader('content-type', 'text/csv; charset=utf-8');

    $csv = $response->streamedContent();

    expect($csv)->toContain('Vendor,Orders,Units,"Gross sales",Commission,"Vendor earnings"')
        ->toContain('Casa Luz')
        ->toContain('1,2,1000,100,900');

    expect($this->get(route('admin.analytics.export', ['report' => 'products']))->streamedContent())
        ->toContain('Table Lamp');
});

test('every export produces a header row and its data', function () {
    actingAsAdmin();

    $vendor = Vendor::factory()->create(['name' => 'Casa Luz']);
    $category = Category::factory()->create(['name' => 'Lighting']);
    $product = Product::factory()->for($vendor)->create(['category_id' => $category->id]);
    $customer = Customer::factory()->create(['first_name' => 'Ishaan', 'last_name' => 'Roy']);

    soldItem(
        $vendor,
        ['product_id' => $product->id, 'name' => 'Table Lamp', 'sku' => 'LAMP-1'],
        ['customer_id' => $customer->id, 'number' => '#7001'],
    );

    $expected = [
        'vendors' => 'Casa Luz',
        'products' => 'Table Lamp',
        'categories' => 'Lighting',
        'customers' => $customer->email,
        'orders' => '#7001',
    ];

    foreach ($expected as $report => $needle) {
        $csv = $this->get(route('admin.analytics.export', ['report' => $report]))
            ->assertOk()
            ->streamedContent();

        expect($csv)->toContain($needle)
            ->and(substr_count(trim($csv), "\n"))->toBe(1, "{$report} should have one data row");
    }
});

test('an unknown export is rejected', function () {
    actingAsAdmin();

    $this->get(route('admin.analytics.export', ['report' => 'secrets']))->assertNotFound();
});
