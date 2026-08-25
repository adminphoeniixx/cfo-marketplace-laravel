<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Roles;

/**
 * A vendor's matrix row governs the seller API and the seller panel, both of
 * which scope every query to the signed-in user's own store. This admin panel
 * scopes to the marketplace, so a vendor reaches none of it.
 *
 * That was not always true. Analytics and Orders used to be open to vendors
 * here on the grounds that both scoped by store. Analytics did.
 * `OrderController::index()` did not — it read a vendor id only as an optional
 * filter — so a signed-in vendor was served every marketplace order and, on
 * `show()`, another store's commission and earnings. The tests below hold the
 * door shut on both.
 */
function vendorInPanel(): User
{
    $vendor = Vendor::factory()->create(['status' => 'approved']);

    $user = User::factory()->create([
        'role' => 'vendor',
        'vendor_id' => $vendor->id,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    test()->actingAs($user);

    return $user;
}

test('the panel view of a vendor grant is empty, however wide the grant is', function () {
    $user = vendorInPanel();

    expect(Roles::forRole('vendor'))->toContain('refunds', 'team', 'shipping', 'products', 'orders', 'analytics')
        ->and(Roles::forPanel($user))->toBe([]);
});

test('every admin screen is shut to a vendor', function () {
    vendorInPanel();

    foreach ([
        'admin.analytics.index',
        'admin.orders.index',
        'admin.cancellations.index',
        'admin.refunds.index',
        'admin.team.index',
        'admin.shipping.index',
        'admin.products.index',
        'admin.payouts.index',
        'admin.customers.index',
        'admin.vendors.index',
    ] as $route) {
        $this->get(route($route))->assertRedirect('/seller');
    }
});

test('the ungated admin routes are shut to a vendor too', function () {
    vendorInPanel();

    // Neither carries a section gate, and neither scopes by store: the
    // dashboard shows marketplace sales and a vendor leaderboard, and search
    // returns every store's orders, products and customers.
    $this->get(route('admin.dashboard'))->assertRedirect('/seller');
    $this->get(route('admin.search', ['q' => 'a']))->assertRedirect('/seller');
});

test('a vendor cannot read the marketplace order book through the admin panel', function () {
    $user = vendorInPanel();
    $theirs = Vendor::factory()->create(['status' => 'approved']);

    $foreign = Order::factory()->create();
    OrderItem::factory()->create([
        'order_id' => $foreign->id,
        'vendor_id' => $theirs->id,
        'product_id' => Product::factory()->create(['vendor_id' => $theirs->id])->id,
        'commission_amount' => 111.11,
        'vendor_earning' => 888.88,
    ]);

    // The index leaked the whole book plus a marketplace-wide revenue figure.
    $this->get(route('admin.orders.index'))->assertRedirect('/seller');

    // `show()` leaked another store's commission through `vendorBreakdown`.
    $this->get(route('admin.orders.show', $foreign))->assertRedirect('/seller');

    expect(Roles::allowsInPanel($user, 'orders'))->toBeFalse();
});

test('staff keep the panel access their own role grants', function () {
    $this->actingAs(User::factory()->create([
        'role' => 'staff',
        'is_active' => true,
        'email_verified_at' => now(),
    ]));

    $this->get(route('admin.orders.index'))->assertOk();
    $this->get(route('admin.refunds.index'))->assertOk();
    $this->get(route('admin.products.index'))->assertForbidden();
});
