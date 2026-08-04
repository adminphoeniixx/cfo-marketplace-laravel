<?php

use App\Models\Setting;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot open store settings', function () {
    $this->get(route('admin.settings.index'))->assertRedirect(route('login'));
});

test('settings fall back to the defaults', function () {
    actingAsAdmin();

    $this->get(route('admin.settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Settings')
            ->where('settings.store_name', 'Marketplace')
            ->where('settings.currency', 'INR')
            ->where('settings.default_commission', '10')
        );
});

test('stored settings override the defaults', function () {
    actingAsAdmin();

    Setting::put('store_name', 'Phoeniixx Bazaar');

    $this->get(route('admin.settings.index'))
        ->assertInertia(fn (Assert $page) => $page->where('settings.store_name', 'Phoeniixx Bazaar'));
});

test('settings can be saved', function () {
    actingAsAdmin();

    $this->from(route('admin.settings.index'))
        ->put(route('admin.settings.update'), [
            'store_name' => 'Phoeniixx Bazaar',
            'store_email' => 'care@phoeniixx.test',
            'store_phone' => '9876543210',
            'currency' => 'INR',
            'weight_unit' => 'kg',
            'order_prefix' => 'PX-',
            'default_commission' => 15,
            'auto_approve_vendors' => true,
            'auto_approve_cancellations' => false,
            'low_stock_threshold' => 3,
            'address' => 'Jaipur, Rajasthan',
        ])
        ->assertSessionHas('success');

    expect(Setting::read('store_name'))->toBe('Phoeniixx Bazaar')
        ->and(Setting::read('default_commission'))->toBe('15')
        ->and(Setting::read('auto_approve_vendors'))->toBe('1')
        ->and(Setting::read('auto_approve_cancellations'))->toBe('0')
        ->and(Setting::read('low_stock_threshold'))->toBe('3');
});

test('saving settings updates existing rows instead of duplicating them', function () {
    actingAsAdmin();

    Setting::put('store_name', 'Old Name');

    $this->put(route('admin.settings.update'), [
        'store_name' => 'New Name',
        'store_email' => 'care@phoeniixx.test',
        'currency' => 'INR',
        'weight_unit' => 'kg',
        'order_prefix' => '#',
        'default_commission' => 10,
        'low_stock_threshold' => 5,
    ])->assertSessionHas('success');

    expect(Setting::where('key', 'store_name')->count())->toBe(1)
        ->and(Setting::read('store_name'))->toBe('New Name');
});

test('settings are validated', function () {
    actingAsAdmin();

    $this->put(route('admin.settings.update'), [
        'store_name' => '',
        'store_email' => 'nope',
        'currency' => 'RUPEES',
        'default_commission' => 250,
        'low_stock_threshold' => -1,
    ])->assertSessionHasErrors([
        'store_name',
        'store_email',
        'currency',
        'weight_unit',
        'order_prefix',
        'default_commission',
        'low_stock_threshold',
    ]);

    $this->assertDatabaseCount('settings', 0);
});
