<?php

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

test('a product can be created, read back, edited and deleted', function () {
    [, $store] = actingAsSeller();
    $category = Category::factory()->create();

    $created = $this->postJson(route('api.seller.products.store'), [
        'name' => 'Hand-loom saree',
        'type' => 'simple',
        'cost_price' => 1800,
        'status' => 'active',
        'track_inventory' => true,
        'stock_quantity' => 12,
        'low_stock_threshold' => 3,
        'category_id' => $category->id,
        'category_ids' => [$category->id],
        'short_description' => 'Woven in Kanchipuram.',
        'tags' => ['saree', 'silk'],
        'images' => [
            ['path' => 'cfo/products/1/a.jpg', 'alt' => 'Front'],
        ],
    ])->assertCreated();

    $id = $created->json('data.id');

    // Asking for `active` sends it to the marketplace to be priced.
    expect($created->json('data.name'))->toBe('Hand-loom saree')
        ->and($created->json('data.status'))->toBe('pending')
        ->and($created->json('data.cost_price'))->toEqual(1800)
        ->and($created->json('data.price'))->toEqual(0)
        ->and($created->json('data.inventory.quantity'))->toBe(12)
        ->and($created->json('data.images'))->toHaveCount(1)
        ->and(Product::find($id)->vendor_id)->toBe($store->id);

    $this->getJson(route('api.seller.products.show', $id))
        ->assertOk()
        ->assertJsonPath('data.slug', fn ($slug) => is_string($slug) && $slug !== '');

    $this->putJson(route('api.seller.products.update', $id), [
        'name' => 'Hand-loom saree (updated)',
        'type' => 'simple',
        'cost_price' => 1850,
        'status' => 'active',
        'stock_quantity' => 8,
    ])->assertOk()->assertJsonPath('data.name', 'Hand-loom saree (updated)');

    // Images not sent back are dropped, matching the admin panel's behaviour.
    expect(Product::find($id)->images()->count())->toBe(0);

    $this->deleteJson(route('api.seller.products.destroy', $id))->assertOk();

    expect(Product::find($id))->toBeNull();
});

test('a variable product keeps its variants and sums their stock', function () {
    actingAsSeller();

    $attribute = Attribute::factory()->create(['is_variant' => true]);
    $small = AttributeValue::factory()->create(['attribute_id' => $attribute->id]);
    $large = AttributeValue::factory()->create(['attribute_id' => $attribute->id]);

    $response = $this->postJson(route('api.seller.products.store'), [
        'name' => 'Cotton kurta',
        'type' => 'variable',
        'status' => 'active',
        'stock_quantity' => 0,
        'attribute_ids' => [$attribute->id],
        'variants' => [
            [
                'name' => 'Small', 'cost_price' => 600, 'stock_quantity' => 4,
                'values' => [['attribute_id' => $attribute->id, 'attribute_value_id' => $small->id]],
            ],
            [
                'name' => 'Large', 'cost_price' => 700, 'stock_quantity' => 6,
                'values' => [['attribute_id' => $attribute->id, 'attribute_value_id' => $large->id]],
            ],
        ],
    ])->assertCreated();

    expect($response->json('data.variants'))->toHaveCount(2)
        ->and($response->json('data.variants.1.cost_price'))->toEqual(700)
        ->and($response->json('data.variants.1.price'))->toEqual(0)
        ->and($response->json('data.inventory.quantity'))->toBe(10);
});

test('status and stock can be changed on their own', function () {
    [, $store] = actingAsSeller();
    $product = Product::factory()->create([
        'vendor_id' => $store->id,
        'status' => 'draft',
        'track_inventory' => true,
        'stock_quantity' => 5,
        'type' => 'simple',
    ]);

    // Going live is the marketplace's call: `active` sends it to be priced.
    $this->patchJson(route('api.seller.products.status', $product->id), ['status' => 'active'])
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');

    $product->forceFill(['status' => 'active'])->save();

    // Once live, a seller can take it down and the status call keeps it live.
    $this->patchJson(route('api.seller.products.status', $product->id), ['status' => 'active'])
        ->assertOk()
        ->assertJsonPath('data.status', 'active');

    $this->patchJson(route('api.seller.products.stock', $product->id), [
        'stock_quantity' => 42,
        'low_stock_threshold' => 7,
    ])->assertOk()->assertJsonPath('data.inventory.quantity', 42);
});

test('stock on a variable product must go through its variants', function () {
    [, $store] = actingAsSeller();
    $product = Product::factory()->create(['vendor_id' => $store->id, 'type' => 'variable']);

    $this->patchJson(route('api.seller.products.stock', $product->id), ['stock_quantity' => 10])
        ->assertStatus(422);
});

test('products can be searched and filtered by low stock', function () {
    [, $store] = actingAsSeller();

    Product::factory()->create(['vendor_id' => $store->id, 'name' => 'Silk scarf', 'status' => 'active']);
    Product::factory()->create([
        'vendor_id' => $store->id,
        'name' => 'Wool shawl',
        'status' => 'draft',
        'track_inventory' => true,
        'stock_quantity' => 1,
        'low_stock_threshold' => 5,
    ]);

    $this->getJson(route('api.seller.products.index', ['search' => 'silk']))
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.name', 'Silk scarf');

    $this->getJson(route('api.seller.products.index', ['status' => 'draft']))
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    $this->getJson(route('api.seller.products.index', ['low_stock' => 1]))
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.name', 'Wool shawl');
});

test('page size is capped so one client cannot pull the whole table', function () {
    [, $store] = actingAsSeller();
    Product::factory()->count(3)->create(['vendor_id' => $store->id]);

    $this->getJson(route('api.seller.products.index', ['per_page' => 5000]))
        ->assertOk()
        ->assertJsonPath('meta.per_page', 100);
});

test('invalid product payloads are rejected', function () {
    actingAsSeller();

    $this->postJson(route('api.seller.products.store'), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'type', 'status']);

    // A simple product has to say what the seller wants paid for it.
    $this->postJson(route('api.seller.products.store'), [
        'name' => 'No cost', 'type' => 'simple', 'status' => 'draft', 'stock_quantity' => 1,
    ])->assertStatus(422)->assertJsonValidationErrors(['cost_price']);
});

test('the catalog reference lists are readable', function () {
    actingAsSeller();
    Category::factory()->create(['is_active' => true]);

    $this->getJson(route('api.seller.catalog.options'))
        ->assertOk()
        ->assertJsonStructure(['categories', 'attributes', 'tax_classes', 'statuses']);

    $this->getJson(route('api.seller.catalog.categories'))->assertOk()->assertJsonStructure(['data']);
});

test('a variant with no id is inserted without writing a null primary key', function () {
    actingAsSeller();

    $attribute = Attribute::factory()->create(['is_variant' => true]);
    $small = AttributeValue::factory()->create(['attribute_id' => $attribute->id]);

    // SQLite quietly hands a NULL integer primary key the next rowid, so the
    // insert has to be inspected directly — on Postgres the same statement is
    // what trips the not-null constraint on product_variants.id.
    $inserts = [];

    DB::listen(function ($query) use (&$inserts) {
        if (str_contains($query->sql, 'insert into "product_variants"')
            || str_contains($query->sql, 'insert into "product_images"')) {
            $inserts[] = $query->sql;
        }
    });

    $this->postJson(route('api.seller.products.store'), [
        'name' => 'Cotton kurta',
        'type' => 'variable',
        'status' => 'active',
        'stock_quantity' => 0,
        'attribute_ids' => [$attribute->id],
        'images' => [
            ['id' => null, 'path' => 'cfo/products/1/a.jpg'],
        ],
        'variants' => [
            [
                'id' => null, 'name' => 'Small', 'cost_price' => 600, 'stock_quantity' => 4,
                'values' => [['attribute_id' => $attribute->id, 'attribute_value_id' => $small->id]],
            ],
        ],
    ])->assertCreated();

    expect($inserts)->toHaveCount(2);

    foreach ($inserts as $sql) {
        expect($sql)->not->toContain('"id"');
    }
});

test('a variant id belonging to another product is not rewritten', function () {
    [, $store] = actingAsSeller();

    $attribute = Attribute::factory()->create(['is_variant' => true]);
    $value = AttributeValue::factory()->create(['attribute_id' => $attribute->id]);

    $other = Product::factory()->create(['vendor_id' => $store->id, 'type' => 'variable']);
    $stranger = $other->variants()->create([
        'name' => 'Untouched', 'price' => 100, 'stock_quantity' => 3, 'position' => 0,
    ]);

    $mine = Product::factory()->create(['vendor_id' => $store->id, 'type' => 'variable']);

    $this->putJson(route('api.seller.products.update', $mine->id), [
        'name' => $mine->name,
        'type' => 'variable',
        'status' => 'active',
        'attribute_ids' => [$attribute->id],
        'variants' => [
            [
                'id' => $stranger->id, 'name' => 'Mine', 'cost_price' => 600, 'stock_quantity' => 7,
                'values' => [['attribute_id' => $attribute->id, 'attribute_value_id' => $value->id]],
            ],
        ],
    ])->assertOk();

    expect($stranger->fresh()->name)->toBe('Untouched')
        ->and($stranger->fresh()->product_id)->toBe($other->id)
        ->and($mine->variants()->count())->toBe(1)
        ->and($mine->variants()->first()->name)->toBe('Mine');
});
