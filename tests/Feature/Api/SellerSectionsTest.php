<?php

use App\Models\Product;
use App\Models\Setting;
use App\Support\Roles;

/**
 * The role matrix is the marketplace's single source of truth for what a
 * vendor may reach. These tests pin that the seller API honours it, and — just
 * as importantly — that the endpoints which belong to the person rather than to
 * a section stay reachable no matter how the matrix is edited.
 */

/** Narrow the vendor role to exactly the sections given. */
function vendorHolds(array $sections): void
{
    Roles::save('vendor', $sections);
}

test('every section the seller app uses is on by default', function () {
    expect(Roles::forRole('vendor'))->toBe([
        'analytics', 'orders', 'cancellations', 'refunds',
        // Shoppers write to stores, and a store without this hears nothing
        // about a question addressed to it.
        'tickets',
        'products', 'shipping', 'payouts', 'team',
    ]);
});

test('a section the marketplace turned off answers 403 with the reason', function () {
    [$me, $store] = actingAsSeller();

    vendorHolds(['orders', 'products']);

    $this->getJson(route('api.seller.payouts.index'))
        ->assertForbidden()
        ->assertJsonPath('section', 'payouts')
        ->assertJsonPath('message', 'The marketplace has turned this off for sellers.');
});

test('the sections still held keep working', function () {
    [$me, $store] = actingAsSeller();
    Product::factory()->create(['vendor_id' => $store->id]);

    vendorHolds(['orders', 'products']);

    $this->getJson(route('api.seller.products.index'))->assertOk();
    $this->getJson(route('api.seller.orders.index'))->assertOk();
});

test('turning a section off closes every endpoint in it, not just the list', function () {
    [$me, $store] = actingAsSeller();
    $product = Product::factory()->create(['vendor_id' => $store->id]);

    vendorHolds(['orders']);

    $this->getJson(route('api.seller.products.index'))->assertForbidden();
    $this->getJson(route('api.seller.products.show', $product->id))->assertForbidden();
    $this->putJson(route('api.seller.products.update', $product->id), [])->assertForbidden();
    $this->deleteJson(route('api.seller.products.destroy', $product->id))->assertForbidden();
    $this->patchJson(route('api.seller.products.status', $product->id), [])->assertForbidden();
    $this->patchJson(route('api.seller.products.stock', $product->id), [])->assertForbidden();
    $this->postJson(route('api.seller.products.bulk'), [])->assertForbidden();

    // The product form's reference data goes with it.
    $this->getJson(route('api.seller.catalog.options'))->assertForbidden();
    $this->getJson(route('api.seller.catalog.categories'))->assertForbidden();
    $this->getJson(route('api.seller.catalog.attributes'))->assertForbidden();
    $this->getJson(route('api.seller.catalog.tax-classes'))->assertForbidden();
});

test('the carrier picker follows orders, not shipping rates', function () {
    [$me] = actingAsSeller();

    vendorHolds(['orders']);
    $this->getJson(route('api.seller.delivery-partners'))->assertOk();
    $this->getJson(route('api.seller.shipping.zones'))->assertForbidden();

    vendorHolds(['shipping']);
    $this->getJson(route('api.seller.delivery-partners'))->assertForbidden();
    $this->getJson(route('api.seller.shipping.zones'))->assertOk();
});

test('an empty matrix still leaves the account reachable', function () {
    [$me, $store] = actingAsSeller();

    // Nothing at all — the harshest setting the panel allows.
    vendorHolds([]);

    $this->getJson(route('api.seller.me'))->assertOk();
    $this->putJson(route('api.seller.me'), ['name' => 'Meera', 'email' => $me->email])->assertOk();
    $this->getJson(route('api.seller.store'))->assertOk();
    $this->getJson(route('api.seller.notifications.index'))->assertOk();
    $this->getJson(route('api.seller.notifications.unread'))->assertOk();
    $this->getJson(route('api.seller.push.settings'))->assertOk();
    $this->getJson(route('api.seller.devices'))->assertOk();
    $this->getJson(route('api.seller.dashboard'))->assertOk();
    $this->postJson(route('api.seller.refresh'))->assertOk();

    // But nothing that belongs to a section.
    $this->getJson(route('api.seller.orders.index'))->assertForbidden();
    $this->getJson(route('api.seller.analytics.sales'))->assertForbidden();
    $this->getJson(route('api.seller.team.index'))->assertForbidden();
    $this->getJson(route('api.seller.refunds.index'))->assertForbidden();
    $this->getJson(route('api.seller.cancellations.index'))->assertForbidden();
});

test('each remaining section is gated by its own key', function () {
    [$me, $store] = actingAsSeller();

    $routes = [
        'analytics' => fn () => $this->getJson(route('api.seller.analytics.sales')),
        'orders' => fn () => $this->getJson(route('api.seller.orders.summary')),
        'cancellations' => fn () => $this->getJson(route('api.seller.cancellations.index')),
        'refunds' => fn () => $this->getJson(route('api.seller.refunds.index')),
        'payouts' => fn () => $this->getJson(route('api.seller.payouts.earnings')),
        'team' => fn () => $this->getJson(route('api.seller.team.index')),
        'shipping' => fn () => $this->getJson(route('api.seller.shipping.rates.index')),
    ];

    foreach ($routes as $section => $call) {
        // Holding only this section opens it...
        vendorHolds([$section]);
        expect($call()->status())->toBe(200, "{$section} should open when held");

        // ...and holding everything except it closes it.
        vendorHolds(array_values(array_diff(array_keys($routes), [$section])));
        expect($call()->status())->toBe(403, "{$section} should close when withheld");
    }
});

test('the app is told which sections it holds', function () {
    [$me] = actingAsSeller();

    vendorHolds(['orders', 'products']);

    $this->getJson(route('api.seller.me'))
        ->assertOk()
        ->assertJsonPath('data.sections', ['orders', 'products']);
});

test('the matrix is read live, so a change takes effect on the next request', function () {
    [$me] = actingAsSeller();

    $this->getJson(route('api.seller.payouts.index'))->assertOk();

    vendorHolds(['orders']);

    $this->getJson(route('api.seller.payouts.index'))->assertForbidden();
});

test('an admin editing the matrix cannot reach past the known sections', function () {
    [$me] = actingAsSeller();

    // A stored value with junk in it must not become a grant.
    Setting::put('role_permissions.vendor', 'orders,not-a-section,,payouts', 'roles');

    expect(Roles::forRole('vendor'))->toBe(['orders', 'payouts']);

    $this->getJson(route('api.seller.orders.index'))->assertOk();
    $this->getJson(route('api.seller.products.index'))->assertForbidden();
});
