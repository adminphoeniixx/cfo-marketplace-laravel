<?php

use App\Models\Order;
use App\Models\Product;
use App\Models\TaxClass;
use App\Models\TaxRate;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot manage taxes', function () {
    $this->get(route('admin.taxes.index'))->assertRedirect(route('login'));
});

test('tax index lists classes with their rates and summary', function () {
    actingAsAdmin();

    $class = TaxClass::factory()->create(['name' => 'GST 18']);
    TaxRate::factory()->create(['tax_class_id' => $class->id, 'rate' => 18]);
    TaxRate::factory()->create(['tax_class_id' => $class->id, 'rate' => 12]);

    Order::factory()->create(['tax_total' => 360, 'status' => 'processing']);
    Order::factory()->cancelled()->create(['tax_total' => 999]);

    $this->get(route('admin.taxes.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/taxes/Index')
            ->has('classes', 1)
            ->has('classes.0.rates', 2)
            ->where('summary.classes', 1)
            ->where('summary.rates', 2)
            ->where('summary.average_rate', 15)
            ->where('summary.collected', 360)
        );

    $this->get(route('admin.taxes.index', ['search' => 'gst']))
        ->assertInertia(fn (Assert $page) => $page->has('classes', 1));
});

test('a tax class can be created', function () {
    actingAsAdmin();

    $this->from(route('admin.taxes.index'))
        ->post(route('admin.taxes.classes.store'), [
            'name' => 'Zero Rated',
            'description' => 'Exempt goods',
            'is_active' => true,
        ])
        ->assertSessionHas('success');

    $this->assertDatabaseHas('tax_classes', ['name' => 'Zero Rated', 'slug' => 'zero-rated']);
});

test('only one tax class stays the default', function () {
    actingAsAdmin();

    $existing = TaxClass::factory()->create(['is_default' => true]);

    $this->post(route('admin.taxes.classes.store'), [
        'name' => 'Standard Rate',
        'is_default' => true,
    ])->assertSessionHas('success');

    expect($existing->fresh()->is_default)->toBeFalse()
        ->and(TaxClass::where('is_default', true)->count())->toBe(1);
});

test('tax class names must be unique', function () {
    actingAsAdmin();

    TaxClass::factory()->create(['name' => 'GST 18']);

    $this->post(route('admin.taxes.classes.store'), ['name' => 'GST 18'])
        ->assertSessionHasErrors('name');
});

test('a tax class can be updated', function () {
    actingAsAdmin();

    $class = TaxClass::factory()->create(['name' => 'Old Class']);

    $this->put(route('admin.taxes.classes.update', $class), [
        'name' => 'Reduced Rate',
        'is_active' => false,
    ])->assertSessionHas('success');

    expect($class->fresh())
        ->name->toBe('Reduced Rate')
        ->slug->toBe('reduced-rate')
        ->is_active->toBeFalse();
});

test('a tax class in use cannot be deleted', function () {
    actingAsAdmin();

    $class = TaxClass::factory()->create();
    Product::factory()->create(['tax_class_id' => $class->id]);

    $this->from(route('admin.taxes.index'))
        ->delete(route('admin.taxes.classes.destroy', $class))
        ->assertSessionHas('error');

    $this->assertDatabaseHas('tax_classes', ['id' => $class->id]);
});

test('an unused tax class can be deleted with its rates', function () {
    actingAsAdmin();

    $class = TaxClass::factory()->create();
    TaxRate::factory()->create(['tax_class_id' => $class->id]);

    $this->from(route('admin.taxes.index'))
        ->delete(route('admin.taxes.classes.destroy', $class))
        ->assertSessionHas('success');

    $this->assertDatabaseMissing('tax_classes', ['id' => $class->id]);
    $this->assertDatabaseCount('tax_rates', 0);
});

test('a tax rate can be added updated and removed', function () {
    actingAsAdmin();

    $class = TaxClass::factory()->create();

    $this->from(route('admin.taxes.index'))
        ->post(route('admin.taxes.rates.store'), [
            'tax_class_id' => $class->id,
            'name' => 'IGST',
            'country' => 'IN',
            'rate' => 18,
            'priority' => 1,
            'is_active' => true,
        ])
        ->assertSessionHas('success');

    $rate = TaxRate::firstWhere('name', 'IGST');

    expect($rate)->not->toBeNull()->and((float) $rate->rate)->toBe(18.0);

    $this->put(route('admin.taxes.rates.update', $rate), [
        'tax_class_id' => $class->id,
        'name' => 'IGST',
        'country' => 'IN',
        'state' => 'Maharashtra',
        'rate' => 12,
        'applies_to_shipping' => true,
    ])->assertSessionHas('success');

    expect($rate->fresh())
        ->state->toBe('Maharashtra')
        ->applies_to_shipping->toBeTrue()
        ->and((float) $rate->fresh()->rate)->toBe(12.0);

    $this->delete(route('admin.taxes.rates.destroy', $rate))->assertSessionHas('success');

    $this->assertDatabaseMissing('tax_rates', ['id' => $rate->id]);
});

test('tax rates are validated', function () {
    actingAsAdmin();

    $this->post(route('admin.taxes.rates.store'), [
        'tax_class_id' => 9999,
        'name' => '',
        'rate' => 150,
        'priority' => 99,
    ])->assertSessionHasErrors(['tax_class_id', 'name', 'country', 'rate', 'priority']);
});
