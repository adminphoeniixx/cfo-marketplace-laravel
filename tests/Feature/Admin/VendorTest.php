<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Vendor;
use App\Models\VendorPayout;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot manage vendors', function () {
    $this->get(route('admin.vendors.index'))->assertRedirect(route('login'));
});

test('vendor index lists vendors with counts and marketplace summary', function () {
    actingAsAdmin();

    $vendor = Vendor::factory()->create();
    Vendor::factory()->pending()->create();
    Vendor::factory()->suspended()->create();

    OrderItem::factory()->create([
        'vendor_id' => $vendor->id,
        'product_id' => Product::factory()->create(['vendor_id' => $vendor->id]),
        'total' => 2000,
        'commission_amount' => 200,
        'vendor_earning' => 1800,
    ]);

    $this->get(route('admin.vendors.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/vendors/Index')
            ->has('vendors.data', 3)
            ->where('counts.all', 3)
            ->where('counts.approved', 1)
            ->where('counts.pending', 1)
            ->where('counts.suspended', 1)
            ->where('summary.gmv', 2000)
            ->where('summary.commission', 200)
            ->where('summary.payable', 1800)
        );
});

test('vendors can be searched and filtered by status', function () {
    actingAsAdmin();

    Vendor::factory()->create(['name' => 'Kraft Studio', 'city' => 'Jaipur']);
    Vendor::factory()->pending()->create(['name' => 'Nimbus Retail']);

    $this->get(route('admin.vendors.index', ['search' => 'kraft']))
        ->assertInertia(fn (Assert $page) => $page->has('vendors.data', 1));

    $this->get(route('admin.vendors.index', ['search' => 'jaipur']))
        ->assertInertia(fn (Assert $page) => $page->has('vendors.data', 1));

    $this->get(route('admin.vendors.index', ['status' => 'pending']))
        ->assertInertia(fn (Assert $page) => $page->has('vendors.data', 1));
});

test('a vendor can be created', function () {
    actingAsAdmin();

    $this->post(route('admin.vendors.store'), [
        'name' => 'Loom & Co',
        'store_email' => 'hello@loom.test',
        'status' => 'approved',
        'commission_type' => 'percentage',
        'commission_rate' => 12.5,
        'country' => 'IN',
        'payout_method' => 'bank',
    ])->assertSessionHas('success');

    $vendor = Vendor::firstWhere('name', 'Loom & Co');

    expect($vendor)->not->toBeNull()
        ->and($vendor->slug)->toStartWith('loom-co')
        ->and((float) $vendor->commission_rate)->toBe(12.5);
});

test('vendor creation is validated', function () {
    actingAsAdmin();

    $this->post(route('admin.vendors.store'), [
        'name' => '',
        'status' => 'unknown',
        'commission_type' => 'barter',
        'commission_rate' => 150,
        'payout_method' => 'cash',
    ])->assertSessionHasErrors(['name', 'status', 'commission_type', 'commission_rate', 'country', 'payout_method']);
});

test('the vendor show screen reports performance', function () {
    actingAsAdmin();

    $vendor = Vendor::factory()->create();
    Product::factory()->count(2)->create(['vendor_id' => $vendor->id]);
    Product::factory()->draft()->create(['vendor_id' => $vendor->id]);

    $order = Order::factory()->create();
    OrderItem::factory()->forOrder($order)->create([
        'vendor_id' => $vendor->id,
        'quantity' => 3,
        'total' => 3000,
        'commission_amount' => 300,
        'vendor_earning' => 2700,
    ]);

    VendorPayout::factory()->paid()->create(['vendor_id' => $vendor->id, 'net_amount' => 2700]);

    $this->get(route('admin.vendors.show', $vendor))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/vendors/Show')
            ->where('stats.revenue', 3000)
            ->where('stats.commission', 300)
            ->where('stats.earning', 2700)
            ->where('stats.units_sold', 3)
            ->where('stats.orders', 1)
            ->where('stats.products', 3)
            ->where('stats.active_products', 2)
            ->where('stats.paid_out', 2700)
            ->has('recentOrders', 1)
            ->has('payouts', 1)
        );
});

test('a vendor can be updated', function () {
    actingAsAdmin();

    $vendor = Vendor::factory()->create();

    $this->put(route('admin.vendors.update', $vendor), [
        'name' => 'Renamed Vendor',
        'status' => 'approved',
        'commission_type' => 'flat',
        'commission_rate' => 50,
        'country' => 'IN',
        'payout_method' => 'upi',
    ])
        ->assertRedirect(route('admin.vendors.show', $vendor))
        ->assertSessionHas('success');

    expect($vendor->fresh())
        ->name->toBe('Renamed Vendor')
        ->commission_type->toBe('flat')
        ->payout_method->toBe('upi');
});

test('approving a vendor stamps the approval date', function () {
    actingAsAdmin();

    $vendor = Vendor::factory()->pending()->create();

    $this->from(route('admin.vendors.show', $vendor))
        ->patch(route('admin.vendors.status', $vendor), ['status' => 'approved'])
        ->assertSessionHas('success');

    expect($vendor->fresh())
        ->status->toBe('approved')
        ->approved_at->not->toBeNull();
});

test('rejecting a vendor requires a reason', function () {
    actingAsAdmin();

    $vendor = Vendor::factory()->pending()->create();

    $this->patch(route('admin.vendors.status', $vendor), ['status' => 'rejected'])
        ->assertSessionHasErrors('rejection_reason');

    $this->patch(route('admin.vendors.status', $vendor), [
        'status' => 'rejected',
        'rejection_reason' => 'Incomplete KYC documents',
    ])->assertSessionHas('success');

    expect($vendor->fresh())
        ->status->toBe('rejected')
        ->rejection_reason->toBe('Incomplete KYC documents');
});

test('suspending a vendor unpublishes its products', function () {
    actingAsAdmin();

    $vendor = Vendor::factory()->create();
    $product = Product::factory()->create(['vendor_id' => $vendor->id, 'status' => 'active']);

    $this->patch(route('admin.vendors.status', $vendor), ['status' => 'suspended'])
        ->assertSessionHas('success');

    expect($vendor->fresh()->status)->toBe('suspended')
        ->and($product->fresh()->status)->toBe('draft');
});

test('a vendor with products cannot be deleted', function () {
    actingAsAdmin();

    $vendor = Vendor::factory()->create();
    Product::factory()->create(['vendor_id' => $vendor->id]);

    $this->from(route('admin.vendors.index'))
        ->delete(route('admin.vendors.destroy', $vendor))
        ->assertSessionHas('error');

    $this->assertDatabaseHas('vendors', ['id' => $vendor->id]);
});

test('a vendor without products can be deleted', function () {
    actingAsAdmin();

    $vendor = Vendor::factory()->create();

    $this->delete(route('admin.vendors.destroy', $vendor))
        ->assertRedirect(route('admin.vendors.index'))
        ->assertSessionHas('success');

    $this->assertDatabaseMissing('vendors', ['id' => $vendor->id]);
});
