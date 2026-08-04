<?php

use App\Models\DeliveryPartner;
use App\Models\Order;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot manage delivery partners', function () {
    $this->post(route('admin.delivery-partners.store'), ['name' => 'Sneaky'])
        ->assertRedirect(route('login'));
});

test('the default couriers ship with the schema', function () {
    expect(DeliveryPartner::pluck('name')->all())
        ->toContain('Delhivery', 'Blue Dart', 'DTDC');
});

test('store settings lists partners with their shipment counts', function () {
    actingAsAdmin();

    DeliveryPartner::query()->delete();
    $partner = DeliveryPartner::factory()->create(['name' => 'Delhivery', 'code' => 'delhivery']);

    Order::factory()->count(2)->create(['carrier' => 'Delhivery']);
    Order::factory()->create(['carrier' => 'Porter']);

    $this->get(route('admin.settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Settings')
            ->has('deliveryPartners', 1)
            ->where('deliveryPartners.0.id', $partner->id)
            ->where('deliveryPartners.0.orders_count', 2)
            ->where('unlistedPartners', ['Porter'])
        );
});

test('a partner can be added, renamed and toggled', function () {
    actingAsAdmin();

    $this->post(route('admin.delivery-partners.store'), [
        'name' => 'XpressBees',
        'tracking_url' => 'https://xpressbees.test/track/{tracking}',
        'support_phone' => '18001234567',
        'is_active' => true,
        'position' => 2,
    ])->assertSessionHas('success');

    $partner = DeliveryPartner::where('name', 'XpressBees')->firstOrFail();

    expect($partner->code)->toBe('xpressbees');

    // Shipped orders carry the name, so a rename has to carry them along.
    $order = Order::factory()->create(['carrier' => 'XpressBees']);

    $this->put(route('admin.delivery-partners.update', $partner), [
        'name' => 'XpressBees Logistics',
        'is_active' => true,
        'position' => 2,
    ])->assertSessionHas('success');

    expect($order->fresh()->carrier)->toBe('XpressBees Logistics');

    $this->patch(route('admin.delivery-partners.toggle', $partner))->assertSessionHas('success');

    expect($partner->fresh()->is_active)->toBeFalse();
});

test('a bad tracking url is rejected', function () {
    actingAsAdmin();

    $this->post(route('admin.delivery-partners.store'), [
        'name' => 'Broken Courier',
        'tracking_url' => 'not-a-url',
    ])->assertSessionHasErrors('tracking_url');
});

test('a partner that has shipped orders cannot be deleted', function () {
    actingAsAdmin();

    DeliveryPartner::query()->delete();
    $partner = DeliveryPartner::factory()->create(['name' => 'Ekart', 'code' => 'ekart']);
    Order::factory()->create(['carrier' => 'Ekart']);

    $this->delete(route('admin.delivery-partners.destroy', $partner))->assertSessionHas('error');

    expect($partner->fresh())->not->toBeNull();

    Order::query()->delete();

    $this->delete(route('admin.delivery-partners.destroy', $partner))->assertSessionHas('success');

    expect($partner->fresh())->toBeNull();
});

test('only active partners are offered when fulfilling an order', function () {
    actingAsAdmin();

    DeliveryPartner::query()->delete();
    DeliveryPartner::factory()->create(['name' => 'Delhivery', 'code' => 'delhivery', 'position' => 0]);
    DeliveryPartner::factory()->inactive()->create(['name' => 'DTDC', 'code' => 'dtdc', 'position' => 1]);

    $order = Order::factory()->create();

    $this->get(route('admin.orders.show', $order))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('deliveryPartners', ['Delhivery']));
});

test('the order page links the tracking number to the partner', function () {
    actingAsAdmin();

    DeliveryPartner::query()->delete();
    DeliveryPartner::factory()->create([
        'name' => 'Delhivery',
        'code' => 'delhivery',
        'tracking_url' => 'https://delhivery.test/track/{tracking}',
    ]);

    $order = Order::factory()->create(['carrier' => 'Delhivery', 'tracking_number' => 'AWB 42/7']);

    $this->get(route('admin.orders.show', $order))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('trackingUrl', 'https://delhivery.test/track/AWB%2042%2F7')
        );
});

test('no tracking link without a tracking number or a configured url', function () {
    actingAsAdmin();

    DeliveryPartner::query()->delete();
    DeliveryPartner::factory()->create([
        'name' => 'Porter',
        'code' => 'porter',
        'tracking_url' => null,
    ]);

    $withoutUrl = Order::factory()->create(['carrier' => 'Porter', 'tracking_number' => 'X1']);
    $withoutNumber = Order::factory()->create(['carrier' => 'Porter', 'tracking_number' => null]);

    foreach ([$withoutUrl, $withoutNumber] as $order) {
        $this->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('trackingUrl', null));
    }
});
