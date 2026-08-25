<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Vendor;

/*
|--------------------------------------------------------------------------
| Manual order entry
|--------------------------------------------------------------------------
|
| The API door onto `CreateManualOrder`. The maths itself is pinned by the
| panel's tests; what these pin is the part that is the API's own: the store
| comes from the token, and nothing from the payload can move it.
|
*/

test('a seller raises an order by hand and gets their share back', function () {
    [$me, $store] = actingAsSeller(['commission_rate' => 10]);
    $customer = Customer::factory()->create();

    $product = Product::factory()->for($store)->create([
        'status' => 'active',
        'price' => 500,
        'track_inventory' => true,
        'stock_quantity' => 10,
    ]);

    $response = $this->postJson(route('api.seller.orders.store'), [
        'customer_id' => $customer->id,
        'email' => 'buyer@example.test',
        'status' => 'processing',
        'payment_status' => 'paid',
        'payment_method' => 'upi',
        'items' => [['product_id' => $product->id, 'quantity' => 2]],
    ])->assertCreated();

    $order = Order::latest('id')->first();

    expect($response->json('data.id'))->toBe($order->id)
        ->and($response->json('data.items'))->toHaveCount(1)
        // JSON drops a trailing `.0`, so compare loosely on the money.
        ->and($response->json('data.totals.items'))->toEqual(1000)
        ->and($response->json('data.totals.commission'))->toEqual(100)
        ->and($response->json('data.totals.earning'))->toEqual(900)
        ->and($response->json('data.to_pack'))->toBe(2)
        ->and($response->json('data.shared_basket'))->toBeFalse()
        ->and($order->items->first()->vendor_id)->toBe($store->id)
        ->and($product->fresh()->stock_quantity)->toBe(8)
        ->and($customer->fresh()->orders_count)->toBe(1);

    // The timeline says which door the order came in through.
    expect($order->events()->first()->meta)
        ->toMatchArray(['vendor_id' => $store->id, 'source' => 'seller-api']);
});

test('a vendor id in the payload is ignored, and another store\'s product refused', function () {
    [, $store] = actingAsSeller();
    $other = Vendor::factory()->create();

    $mine = Product::factory()->for($store)->create(['status' => 'active', 'price' => 200]);
    $theirs = Product::factory()->for($other)->create(['status' => 'active', 'price' => 200]);

    // A forged vendor_id changes nothing: the token decides.
    $this->postJson(route('api.seller.orders.store'), [
        'vendor_id' => $other->id,
        'email' => 'buyer@example.test',
        'status' => 'pending',
        'payment_status' => 'pending',
        'items' => [['product_id' => $mine->id, 'quantity' => 1]],
    ])->assertCreated();

    expect(Order::latest('id')->first()->items->first()->vendor_id)->toBe($store->id);

    // And a product that is not ours cannot be sold under our name.
    $this->postJson(route('api.seller.orders.store'), [
        'email' => 'buyer@example.test',
        'status' => 'pending',
        'payment_status' => 'pending',
        'items' => [['product_id' => $theirs->id, 'quantity' => 1]],
    ])->assertStatus(422)->assertJsonValidationErrors('items.0.product_id');

    expect(Order::count())->toBe(1);
});

test('an order cannot be raised beyond the stock on hand', function () {
    [, $store] = actingAsSeller();

    $product = Product::factory()->for($store)->create([
        'status' => 'active',
        'track_inventory' => true,
        'allow_backorder' => false,
        'stock_quantity' => 3,
    ]);

    $this->postJson(route('api.seller.orders.store'), [
        'email' => 'buyer@example.test',
        'status' => 'pending',
        'payment_status' => 'pending',
        'items' => [['product_id' => $product->id, 'quantity' => 4]],
    ])->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');

    expect(Order::count())->toBe(0)
        ->and($product->fresh()->stock_quantity)->toBe(3);
});

test('the order form is fed only this store\'s sellable products', function () {
    [, $store] = actingAsSeller();

    $sellable = Product::factory()->for($store)->create(['status' => 'active', 'name' => 'Sellable']);
    Product::factory()->for($store)->create(['status' => 'draft', 'name' => 'Draft']);
    Product::factory()->create(['status' => 'active', 'name' => 'Another store\'s']);

    $response = $this->getJson(route('api.seller.orders.sellable'))->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($sellable->id)
        ->and($response->json('data.0'))->toHaveKeys(['price', 'stock_quantity', 'tax_rate', 'variants']);
});

test('the customer picker holds only people who have bought from this store', function () {
    [, $store] = actingAsSeller();

    $mine = Customer::factory()->create(['first_name' => 'Meera', 'last_name' => 'Nair']);
    orderForStore($store)->update(['customer_id' => $mine->id]);

    // Bought elsewhere on the marketplace — not this store's to see.
    $stranger = Customer::factory()->create(['first_name' => 'Rahul']);
    orderForStore(Vendor::factory()->create())->update(['customer_id' => $stranger->id]);

    $response = $this->getJson(route('api.seller.orders.customers'))->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($mine->id)
        ->and($response->json('data.0.name'))->toBe('Meera Nair');

    $this->getJson(route('api.seller.orders.customers', ['search' => 'Rahul']))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

/*
|--------------------------------------------------------------------------
| Order detail
|--------------------------------------------------------------------------
*/

test('the detail carries a timeline with another seller\'s events held back', function () {
    [, $store] = actingAsSeller();
    $other = Vendor::factory()->create();

    $order = orderForStore($store, ['quantity' => 3, 'quantity_fulfilled' => 1]);
    $order->items()->create([
        'vendor_id' => $other->id,
        'name' => 'Their item',
        'sku' => 'SKU-OTHER-1',
        'unit_price' => 100,
        'quantity' => 1,
        'total' => 100,
    ]);

    $order->recordEvent('status', 'Marketplace moved it', 'Marked processing.');
    $order->recordEvent('note', 'Our note', 'Packed tomorrow.', ['vendor_id' => $store->id]);
    $order->recordEvent('note', 'Their note', 'Not our business.', ['vendor_id' => $other->id]);

    $response = $this->getJson(route('api.seller.orders.show', $order->id))->assertOk();

    expect($response->json('data.items'))->toHaveCount(1)
        ->and($response->json('data.shared_basket'))->toBeTrue()
        // Three ordered, one packed: two still ours.
        ->and($response->json('data.to_pack'))->toBe(2)
        ->and($response->json('data.timeline'))->toHaveCount(2)
        ->and(collect($response->json('data.timeline'))->pluck('title')->all())
        ->not->toContain('Their note');

    expect(collect($response->json('data.timeline'))->firstWhere('title', 'Our note')['by'])->toBe('store')
        ->and(collect($response->json('data.timeline'))->firstWhere('title', 'Marketplace moved it')['by'])->toBe('marketplace');
});

test('an order with none of our lines is a 404, timeline and all', function () {
    actingAsSeller();

    $foreign = orderForStore(Vendor::factory()->create());

    $this->getJson(route('api.seller.orders.show', $foreign->id))->assertNotFound();
});
