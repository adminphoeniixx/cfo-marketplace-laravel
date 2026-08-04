<?php

use App\Models\Category;
use App\Models\Product;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot manage categories', function () {
    $this->get(route('admin.categories.index'))->assertRedirect(route('login'));
});

test('category index lists categories with counts and filters', function () {
    actingAsAdmin();

    $parent = Category::factory()->create(['name' => 'Apparel']);
    Category::factory()->childOf($parent)->create(['name' => 'Shirts']);
    Category::factory()->inactive()->create(['name' => 'Clearance']);

    $this->get(route('admin.categories.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/categories/Index')
            ->has('categories.data', 3)
            ->where('counts.all', 3)
            ->where('counts.active', 2)
            ->where('counts.inactive', 1)
            ->has('parents', 2)
        );

    $this->get(route('admin.categories.index', ['search' => 'shirt']))
        ->assertInertia(fn (Assert $page) => $page->has('categories.data', 1));

    $this->get(route('admin.categories.index', ['status' => 'inactive']))
        ->assertInertia(fn (Assert $page) => $page->has('categories.data', 1));

    $this->get(route('admin.categories.index', ['parent' => $parent->id]))
        ->assertInertia(fn (Assert $page) => $page->has('categories.data', 1));
});

test('category create screen renders', function () {
    actingAsAdmin();

    $this->get(route('admin.categories.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/categories/Form')
            ->where('category', null)
        );
});

test('a category can be created', function () {
    actingAsAdmin();

    $this->post(route('admin.categories.store'), [
        'name' => 'Winter Wear',
        'description' => 'Jackets and more',
        'is_active' => true,
        'is_featured' => false,
    ])
        ->assertRedirect(route('admin.categories.index'))
        ->assertSessionHas('success');

    $this->assertDatabaseHas('categories', [
        'name' => 'Winter Wear',
        'slug' => 'winter-wear',
        'position' => 0,
    ]);
});

test('category slugs are made unique', function () {
    actingAsAdmin();

    Category::factory()->create(['name' => 'Winter Wear', 'slug' => 'winter-wear']);

    $this->post(route('admin.categories.store'), [
        'name' => 'Winter Wear',
        'is_active' => true,
        'is_featured' => false,
    ])->assertRedirect(route('admin.categories.index'));

    $this->assertDatabaseHas('categories', ['slug' => 'winter-wear-2']);
});

test('category name is required', function () {
    actingAsAdmin();

    $this->post(route('admin.categories.store'), ['name' => ''])
        ->assertSessionHasErrors('name');

    $this->assertDatabaseCount('categories', 0);
});

test('a category can be updated', function () {
    actingAsAdmin();

    $category = Category::factory()->create(['name' => 'Old Name']);

    $this->put(route('admin.categories.update', $category), [
        'name' => 'New Name',
        'position' => 3,
        'is_active' => false,
        'is_featured' => true,
    ])
        ->assertRedirect(route('admin.categories.index'))
        ->assertSessionHas('success');

    expect($category->fresh())
        ->name->toBe('New Name')
        ->position->toBe(3)
        ->is_active->toBeFalse()
        ->is_featured->toBeTrue();
});

test('a category cannot be its own parent', function () {
    actingAsAdmin();

    $category = Category::factory()->create();

    $this->put(route('admin.categories.update', $category), [
        'name' => $category->name,
        'parent_id' => $category->id,
    ])->assertSessionHasErrors('parent_id');
});

test('deleting a category detaches its products', function () {
    actingAsAdmin();

    $category = Category::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id]);

    $this->delete(route('admin.categories.destroy', $category))
        ->assertRedirect(route('admin.categories.index'))
        ->assertSessionHas('success');

    $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    expect($product->fresh()->category_id)->toBeNull();
});

test('a category with children cannot be deleted', function () {
    actingAsAdmin();

    $parent = Category::factory()->create();
    Category::factory()->childOf($parent)->create();

    $this->from(route('admin.categories.index'))
        ->delete(route('admin.categories.destroy', $parent))
        ->assertRedirect(route('admin.categories.index'))
        ->assertSessionHas('error');

    $this->assertDatabaseHas('categories', ['id' => $parent->id]);
});
