<?php

use App\Models\User;
use App\Models\Vendor;
use App\Support\Roles;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A vendor's matrix row governs two very different surfaces.
 *
 * The seller API scopes every query to the store, so the whole row applies
 * there. The admin panel only scopes some of its screens that way, so a vendor
 * login is held to those — otherwise granting "Refunds" for the app would also
 * hand one seller every other seller's refunds in the panel.
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

test('the panel view of a vendor grant is narrower than the grant itself', function () {
    $user = vendorInPanel();

    expect(Roles::forRole('vendor'))->toContain('refunds', 'team', 'shipping', 'products')
        ->and(Roles::forPanel($user))->toBe(['analytics', 'orders']);
});

test('a vendor only reaches the panel screens the panel actually scopes', function () {
    vendorInPanel();

    $this->get(route('admin.analytics.index'))->assertOk();
    $this->get(route('admin.orders.index'))->assertOk();
});

test('the wider half of the grant stays shut in the panel', function () {
    vendorInPanel();

    foreach ([
        'admin.cancellations.index',
        'admin.refunds.index',
        'admin.team.index',
        'admin.shipping.index',
        'admin.products.index',
        'admin.payouts.index',
    ] as $route) {
        $this->get(route($route))->assertForbidden();
    }
});

test('the sidebar is drawn from the panel view, so no link leads to a 403', function () {
    vendorInPanel();

    $this->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('auth.sections', ['analytics', 'orders']));
});

test('narrowing the matrix still closes a panel section', function () {
    vendorInPanel();

    Roles::save('vendor', ['analytics']);

    $this->get(route('admin.analytics.index'))->assertOk();
    $this->get(route('admin.orders.index'))->assertForbidden();
});

test('a section the panel could scope is still refused unless the matrix grants it', function () {
    vendorInPanel();

    Roles::save('vendor', []);

    $this->get(route('admin.analytics.index'))->assertForbidden();
    $this->get(route('admin.orders.index'))->assertForbidden();
});

test('marketplace staff are not narrowed at all', function () {
    $manager = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    expect(Roles::forPanel($manager))->toBe(Roles::forRole('manager'))
        ->and(Roles::forPanel($manager))->toContain('refunds', 'cancellations');

    $this->actingAs($manager)->get(route('admin.refunds.index'))->assertOk();
});

test('an admin still holds everything', function () {
    $admin = adminUser();

    expect(Roles::forPanel($admin))->toBe(Roles::sectionKeys());
});

test('a deactivated user holds nothing in either view', function () {
    $user = vendorInPanel();
    $user->forceFill(['is_active' => false])->save();

    expect(Roles::forPanel($user))->toBe([])
        ->and(Roles::allowsInPanel($user, 'orders'))->toBeFalse()
        ->and(Roles::allows($user, 'orders'))->toBeFalse();
});

test('the seller API is unaffected by the panel narrowing', function () {
    // The same grant that is narrowed in the panel is honoured in full here.
    [$me, $store] = actingAsSeller();

    $this->getJson(route('api.seller.refunds.index'))->assertOk();
    $this->getJson(route('api.seller.team.index'))->assertOk();
    $this->getJson(route('api.seller.shipping.zones'))->assertOk();
    $this->getJson(route('api.seller.cancellations.index'))->assertOk();
    $this->getJson(route('api.seller.products.index'))->assertOk();
    $this->getJson(route('api.seller.payouts.index'))->assertOk();
});
