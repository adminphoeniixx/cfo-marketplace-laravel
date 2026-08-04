<?php

use App\Models\Order;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\Vendor;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot manage shipping', function () {
    $this->get(route('admin.shipping.index'))->assertRedirect(route('login'));
});

test('shipping index lists zones with rates and summary', function () {
    actingAsAdmin();

    $zone = ShippingZone::factory()->create(['name' => 'West India Zone']);
    ShippingRate::factory()->create(['shipping_zone_id' => $zone->id]);
    ShippingRate::factory()->create(['shipping_zone_id' => $zone->id, 'type' => 'free', 'rate' => 0]);

    Order::factory()->create(['shipping_total' => 79, 'status' => 'processing']);
    Order::factory()->cancelled()->create(['shipping_total' => 500]);

    $this->get(route('admin.shipping.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/shipping/Index')
            ->has('zones', 1)
            ->has('zones.0.rates', 2)
            ->where('summary.zones', 1)
            ->where('summary.rates', 2)
            ->where('summary.free_rates', 1)
            ->where('summary.collected', 79)
        );

    $this->get(route('admin.shipping.index', ['search' => 'west']))
        ->assertInertia(fn (Assert $page) => $page->has('zones', 1));
});

test('a shipping zone can be created updated and deleted', function () {
    actingAsAdmin();

    $this->from(route('admin.shipping.index'))
        ->post(route('admin.shipping.zones.store'), [
            'name' => 'Metro Cities',
            'countries' => ['IN'],
            'states' => ['Maharashtra', 'Delhi'],
            'is_active' => true,
            'position' => 1,
        ])
        ->assertSessionHas('success');

    $zone = ShippingZone::firstWhere('name', 'Metro Cities');

    expect($zone)->not->toBeNull()
        ->and($zone->states)->toBe(['Maharashtra', 'Delhi']);

    $this->put(route('admin.shipping.zones.update', $zone), [
        'name' => 'Metro & Tier 2',
        'countries' => ['IN'],
        'is_active' => false,
    ])->assertSessionHas('success');

    expect($zone->fresh())
        ->name->toBe('Metro & Tier 2')
        ->is_active->toBeFalse();

    $this->delete(route('admin.shipping.zones.destroy', $zone))->assertSessionHas('success');

    $this->assertDatabaseMissing('shipping_zones', ['id' => $zone->id]);
});

test('deleting a zone removes its rates', function () {
    actingAsAdmin();

    $zone = ShippingZone::factory()->create();
    ShippingRate::factory()->count(2)->create(['shipping_zone_id' => $zone->id]);

    $this->from(route('admin.shipping.index'))
        ->delete(route('admin.shipping.zones.destroy', $zone))
        ->assertSessionHas('success');

    $this->assertDatabaseCount('shipping_rates', 0);
});

test('zone names are required', function () {
    actingAsAdmin();

    $this->post(route('admin.shipping.zones.store'), ['name' => ''])
        ->assertSessionHasErrors('name');
});

test('a vendor specific shipping rate can be created', function () {
    actingAsAdmin();

    $zone = ShippingZone::factory()->create();
    $vendor = Vendor::factory()->create();

    $this->from(route('admin.shipping.index'))
        ->post(route('admin.shipping.rates.store'), [
            'shipping_zone_id' => $zone->id,
            'vendor_id' => $vendor->id,
            'name' => 'Express',
            'type' => 'flat',
            'rate' => 149,
            'delivery_days_min' => 1,
            'delivery_days_max' => 3,
            'is_active' => true,
        ])
        ->assertSessionHas('success');

    $this->assertDatabaseHas('shipping_rates', [
        'shipping_zone_id' => $zone->id,
        'vendor_id' => $vendor->id,
        'name' => 'Express',
    ]);
});

test('shipping rate ranges are validated', function () {
    actingAsAdmin();

    $zone = ShippingZone::factory()->create();

    $this->post(route('admin.shipping.rates.store'), [
        'shipping_zone_id' => $zone->id,
        'name' => 'Broken',
        'type' => 'flat',
        'rate' => 100,
        'min_order_amount' => 500,
        'max_order_amount' => 100,
        'delivery_days_min' => 5,
        'delivery_days_max' => 2,
    ])->assertSessionHasErrors(['max_order_amount', 'delivery_days_max']);

    $this->post(route('admin.shipping.rates.store'), [
        'shipping_zone_id' => 9999,
        'name' => 'Broken',
        'type' => 'teleport',
        'rate' => -5,
    ])->assertSessionHasErrors(['shipping_zone_id', 'type', 'rate']);
});

test('a shipping rate can be updated and deleted', function () {
    actingAsAdmin();

    $rate = ShippingRate::factory()->create(['name' => 'Standard']);

    $this->from(route('admin.shipping.index'))
        ->put(route('admin.shipping.rates.update', $rate), [
            'shipping_zone_id' => $rate->shipping_zone_id,
            'name' => 'Standard Plus',
            'type' => 'weight_based',
            'rate' => 60,
            'per_kg_rate' => 20,
        ])
        ->assertSessionHas('success');

    expect($rate->fresh())
        ->name->toBe('Standard Plus')
        ->type->toBe('weight_based')
        ->and((float) $rate->fresh()->per_kg_rate)->toBe(20.0);

    $this->delete(route('admin.shipping.rates.destroy', $rate))->assertSessionHas('success');

    $this->assertDatabaseMissing('shipping_rates', ['id' => $rate->id]);
});
