<?php

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Product;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot manage attributes', function () {
    $this->get(route('admin.attributes.index'))->assertRedirect(route('login'));
});

test('attribute index lists attributes with their values', function () {
    actingAsAdmin();

    $size = Attribute::factory()->create(['name' => 'Size', 'type' => 'dropdown']);
    AttributeValue::factory()->count(3)->create(['attribute_id' => $size->id]);
    Attribute::factory()->create(['name' => 'Colour', 'type' => 'swatch']);

    $this->get(route('admin.attributes.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/attributes/Index')
            ->has('attributes.data', 2)
            ->has('types')
        );

    $this->get(route('admin.attributes.index', ['search' => 'siz']))
        ->assertInertia(fn (Assert $page) => $page->has('attributes.data', 1));

    $this->get(route('admin.attributes.index', ['type' => 'swatch']))
        ->assertInertia(fn (Assert $page) => $page->has('attributes.data', 1));
});

test('an attribute can be created with values', function () {
    actingAsAdmin();

    $this->post(route('admin.attributes.store'), [
        'name' => 'Size',
        'type' => 'dropdown',
        'is_variant' => true,
        'is_filterable' => true,
        'is_active' => true,
        'values' => [
            ['value' => 'Small'],
            ['value' => 'Medium'],
        ],
    ])
        ->assertRedirect(route('admin.attributes.index'))
        ->assertSessionHas('success');

    $attribute = Attribute::firstWhere('slug', 'size');

    expect($attribute)->not->toBeNull()
        ->and($attribute->values)->toHaveCount(2)
        ->and($attribute->values->pluck('slug')->all())->toBe(['small', 'medium'])
        ->and($attribute->values->pluck('position')->all())->toBe([0, 1]);
});

test('an attribute requires at least one value', function () {
    actingAsAdmin();

    $this->post(route('admin.attributes.store'), [
        'name' => 'Size',
        'type' => 'dropdown',
        'values' => [],
    ])->assertSessionHasErrors('values');

    $this->assertDatabaseCount('attributes', 0);
});

test('an attribute cannot be stored without a values key', function () {
    actingAsAdmin();

    $this->post(route('admin.attributes.store'), [
        'name' => 'Size',
        'type' => 'dropdown',
    ])->assertSessionHasErrors('values');

    $this->assertDatabaseCount('attributes', 0);
});

test('the attribute type must be supported', function () {
    actingAsAdmin();

    $this->post(route('admin.attributes.store'), [
        'name' => 'Size',
        'type' => 'carousel',
        'values' => [['value' => 'Small']],
    ])->assertSessionHasErrors('type');
});

test('updating an attribute syncs its values', function () {
    actingAsAdmin();

    $attribute = Attribute::factory()->create(['name' => 'Size']);
    $keep = AttributeValue::factory()->create(['attribute_id' => $attribute->id, 'value' => 'Small']);
    $drop = AttributeValue::factory()->create(['attribute_id' => $attribute->id, 'value' => 'Large']);

    $this->put(route('admin.attributes.update', $attribute), [
        'name' => 'Size',
        'type' => 'dropdown',
        'values' => [
            ['id' => $keep->id, 'value' => 'Small'],
            ['value' => 'Extra Large'],
        ],
    ])
        ->assertRedirect(route('admin.attributes.index'))
        ->assertSessionHas('success');

    $values = $attribute->fresh()->values;

    expect($values->pluck('value')->all())->toBe(['Small', 'Extra Large']);
    $this->assertDatabaseMissing('attribute_values', ['id' => $drop->id]);
});

test('an unused attribute can be deleted', function () {
    actingAsAdmin();

    $attribute = Attribute::factory()->create();
    AttributeValue::factory()->create(['attribute_id' => $attribute->id]);

    $this->delete(route('admin.attributes.destroy', $attribute))
        ->assertRedirect(route('admin.attributes.index'))
        ->assertSessionHas('success');

    $this->assertDatabaseMissing('attributes', ['id' => $attribute->id]);
    $this->assertDatabaseCount('attribute_values', 0);
});

test('an attribute used by a product cannot be deleted', function () {
    actingAsAdmin();

    $attribute = Attribute::factory()->create();
    Product::factory()->create()->attributes()->attach($attribute);

    $this->from(route('admin.attributes.index'))
        ->delete(route('admin.attributes.destroy', $attribute))
        ->assertRedirect(route('admin.attributes.index'))
        ->assertSessionHas('error');

    $this->assertDatabaseHas('attributes', ['id' => $attribute->id]);
});
