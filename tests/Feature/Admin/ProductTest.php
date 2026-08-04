<?php

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Vendor;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot manage products', function () {
    $this->get(route('admin.products.index'))->assertRedirect(route('login'));
});

test('product index lists products with counts', function () {
    actingAsAdmin();

    Product::factory()->count(2)->create();
    Product::factory()->draft()->create();

    $this->get(route('admin.products.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/products/Index')
            ->has('products.data', 3)
            ->where('counts.all', 3)
            ->where('counts.active', 2)
            ->where('counts.draft', 1)
        );
});

test('products can be filtered', function () {
    actingAsAdmin();

    $vendor = Vendor::factory()->create();
    $category = Category::factory()->create();

    Product::factory()->create([
        'name' => 'Merino Wool Sweater',
        'vendor_id' => $vendor->id,
        'category_id' => $category->id,
        'stock_quantity' => 2,
        'low_stock_threshold' => 5,
    ]);
    Product::factory()->outOfStock()->create(['name' => 'Cotton Cap']);
    Product::factory()->draft()->create(['name' => 'Denim Jacket', 'stock_quantity' => 50]);

    $this->get(route('admin.products.index', ['search' => 'merino']))
        ->assertInertia(fn (Assert $page) => $page->has('products.data', 1));

    $this->get(route('admin.products.index', ['status' => 'draft']))
        ->assertInertia(fn (Assert $page) => $page->has('products.data', 1));

    $this->get(route('admin.products.index', ['vendor' => $vendor->id]))
        ->assertInertia(fn (Assert $page) => $page->has('products.data', 1));

    $this->get(route('admin.products.index', ['category' => $category->id]))
        ->assertInertia(fn (Assert $page) => $page->has('products.data', 1));

    $this->get(route('admin.products.index', ['stock' => 'out']))
        ->assertInertia(fn (Assert $page) => $page->has('products.data', 1));

    $this->get(route('admin.products.index', ['stock' => 'low']))
        ->assertInertia(fn (Assert $page) => $page->has('products.data', 1));
});

test('the product form exposes only approved vendors', function () {
    actingAsAdmin();

    Vendor::factory()->create(['name' => 'Approved Co']);
    Vendor::factory()->pending()->create(['name' => 'Pending Co']);

    $this->get(route('admin.products.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/products/Form')
            ->where('product', null)
            ->has('vendors', 1)
            ->where('vendors.0.name', 'Approved Co')
        );
});

test('a simple product can be created', function () {
    actingAsAdmin();

    $vendor = Vendor::factory()->create();
    $category = Category::factory()->create();

    $this->post(route('admin.products.store'), [
        'name' => 'Classic Oxford Shirt',
        'type' => 'simple',
        'vendor_id' => $vendor->id,
        'category_id' => $category->id,
        'price' => 1999,
        'stock_quantity' => 25,
        'status' => 'active',
        'track_inventory' => true,
        'category_ids' => [$category->id],
        'images' => [
            ['path' => 'products/oxford-1.jpg', 'alt' => 'Front'],
        ],
    ])->assertSessionHas('success');

    $product = Product::firstWhere('name', 'Classic Oxford Shirt');

    expect($product)->not->toBeNull()
        ->and($product->slug)->toBe('classic-oxford-shirt')
        ->and($product->stock_quantity)->toBe(25)
        ->and($product->published_at)->not->toBeNull()
        ->and($product->images)->toHaveCount(1)
        ->and($product->categories->pluck('id')->all())->toBe([$category->id]);
});

test('creating a product requires a name price and status', function () {
    actingAsAdmin();

    $this->post(route('admin.products.store'), [])
        ->assertSessionHasErrors(['name', 'price', 'status', 'type']);

    $this->assertDatabaseCount('products', 0);
});

test('product skus must be unique', function () {
    actingAsAdmin();

    Product::factory()->create(['sku' => 'SKU-DUP-1']);

    $this->post(route('admin.products.store'), [
        'name' => 'Another Product',
        'type' => 'simple',
        'price' => 100,
        'sku' => 'SKU-DUP-1',
        'status' => 'draft',
        'stock_quantity' => 1,
    ])->assertSessionHasErrors('sku');
});

test('a variable product syncs variants and parent stock', function () {
    actingAsAdmin();

    $attribute = Attribute::factory()->create(['name' => 'Size']);
    $small = AttributeValue::factory()->create(['attribute_id' => $attribute->id, 'value' => 'Small']);
    $large = AttributeValue::factory()->create(['attribute_id' => $attribute->id, 'value' => 'Large']);

    $this->post(route('admin.products.store'), [
        'name' => 'Everyday Tee',
        'type' => 'variable',
        'price' => 899,
        'status' => 'active',
        'stock_quantity' => 0,
        'attribute_ids' => [$attribute->id],
        'variants' => [
            [
                'name' => 'Small',
                'price' => 899,
                'stock_quantity' => 4,
                'values' => [['attribute_id' => $attribute->id, 'attribute_value_id' => $small->id]],
            ],
            [
                'name' => 'Large',
                'price' => 999,
                'stock_quantity' => 6,
                'values' => [['attribute_id' => $attribute->id, 'attribute_value_id' => $large->id]],
            ],
        ],
    ])->assertSessionHas('success');

    $product = Product::firstWhere('name', 'Everyday Tee');

    expect($product->variants)->toHaveCount(2)
        ->and($product->stock_quantity)->toBe(10)
        ->and($product->attributes)->toHaveCount(1)
        ->and($product->variants->first()->values)->toHaveCount(1);
});

test('switching a variable product to simple removes its variants', function () {
    actingAsAdmin();

    $product = Product::factory()->create(['type' => 'variable']);
    $product->variants()->create(['name' => 'Small', 'price' => 100, 'stock_quantity' => 5]);

    $this->put(route('admin.products.update', $product), [
        'name' => $product->name,
        'type' => 'simple',
        'price' => 150,
        'status' => 'active',
        'stock_quantity' => 9,
    ])->assertSessionHas('success');

    expect($product->fresh())
        ->type->toBe('simple')
        ->stock_quantity->toBe(9)
        ->and($product->variants()->count())->toBe(0);
});

test('the show route redirects to the edit screen', function () {
    actingAsAdmin();

    $product = Product::factory()->create();

    $this->get(route('admin.products.show', $product))
        ->assertRedirect(route('admin.products.edit', $product));
});

test('the edit screen reports sales stats', function () {
    actingAsAdmin();

    $product = Product::factory()->create();
    $product->orderItems()->create([
        'order_id' => Order::factory()->create()->id,
        'name' => $product->name,
        'unit_price' => 500,
        'quantity' => 2,
        'total' => 1000,
    ]);

    $this->get(route('admin.products.edit', $product))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/products/Form')
            ->where('stats.units_sold', 2)
            ->where('stats.revenue', 1000)
            ->where('stats.orders', 1)
        );
});

test('deleting a product soft deletes it', function () {
    actingAsAdmin();

    $product = Product::factory()->create();

    $this->delete(route('admin.products.destroy', $product))
        ->assertRedirect(route('admin.products.index'))
        ->assertSessionHas('success');

    $this->assertSoftDeleted('products', ['id' => $product->id]);
});

test('bulk actions update the selected products', function () {
    actingAsAdmin();

    $products = Product::factory()->draft()->count(3)->create();

    $this->from(route('admin.products.index'))
        ->post(route('admin.products.bulk'), [
            'action' => 'activate',
            'ids' => $products->pluck('id')->all(),
        ])
        ->assertRedirect(route('admin.products.index'))
        ->assertSessionHas('success');

    expect(Product::where('status', 'active')->count())->toBe(3);

    $this->post(route('admin.products.bulk'), [
        'action' => 'delete',
        'ids' => $products->pluck('id')->all(),
    ]);

    expect(Product::count())->toBe(0);
});

test('bulk actions validate the action and ids', function () {
    actingAsAdmin();

    $this->post(route('admin.products.bulk'), ['action' => 'explode', 'ids' => []])
        ->assertSessionHasErrors(['action', 'ids']);

    $this->post(route('admin.products.bulk'), ['action' => 'activate', 'ids' => [9999]])
        ->assertSessionHasErrors('ids.0');
});
