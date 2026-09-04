<?php

use App\Models\Coupon;
use App\Models\ProductVariant;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\Vendor;

beforeEach(function () {
    $this->customer = actingAsCustomer();
});

test('adding something prices the whole basket back', function () {
    $product = sellableProduct(['price' => 1000, 'compare_at_price' => 1500]);

    $response = $this->postJson(route('api.customer.cart.items.store'), [
        'product_id' => $product->id,
        'quantity' => 2,
    ])->assertOk();

    $response->assertJsonPath('data.totals.items_count', 2)
        ->assertJsonPath('data.totals.subtotal', 2000)
        ->assertJsonPath('data.totals.mrp_total', 3000)
        ->assertJsonPath('data.totals.saved_on_mrp', 1000)
        ->assertJsonPath('data.groups.0.items.0.quantity', 2);
});

test('adding the same line twice adds up instead of duplicating', function () {
    $product = sellableProduct();

    foreach (range(1, 2) as $ignored) {
        $this->postJson(route('api.customer.cart.items.store'), ['product_id' => $product->id]);
    }

    $this->getJson(route('api.customer.cart'))
        ->assertJsonPath('data.totals.items_count', 2)
        ->assertJsonCount(1, 'data.groups.0.items');
});

test('a product built out of variants cannot be bought as itself', function () {
    $product = sellableProduct(['type' => 'variable']);
    ProductVariant::create([
        'product_id' => $product->id,
        'name' => 'M',
        'sku' => 'KUR-M',
        'price' => 999,
        'stock_quantity' => 5,
        'is_active' => true,
    ]);

    $this->postJson(route('api.customer.cart.items.store'), ['product_id' => $product->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('product_variant_id');
});

test('a variant of another product is refused', function () {
    $product = sellableProduct();
    $other = sellableProduct();
    $variant = ProductVariant::create([
        'product_id' => $other->id, 'name' => 'M', 'sku' => 'X-M', 'price' => 1, 'stock_quantity' => 1, 'is_active' => true,
    ]);

    $this->postJson(route('api.customer.cart.items.store'), [
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
    ])->assertStatus(422)->assertJsonValidationErrors('product_variant_id');
});

test('the basket says when a line has outrun the stock', function () {
    $product = sellableProduct(['stock_quantity' => 1]);

    $this->postJson(route('api.customer.cart.items.store'), ['product_id' => $product->id, 'quantity' => 3])
        ->assertOk()
        ->assertJsonPath('data.groups.0.items.0.in_stock', false)
        ->assertJsonPath('data.groups.0.items.0.available', 1);
});

test('quantity can be changed and a line removed', function () {
    $product = sellableProduct();
    $item = $this->postJson(route('api.customer.cart.items.store'), ['product_id' => $product->id])
        ->json('data.groups.0.items.0.id');

    $this->patchJson(route('api.customer.cart.items.update', $item), ['quantity' => 4])
        ->assertOk()
        ->assertJsonPath('data.totals.items_count', 4);

    $this->deleteJson(route('api.customer.cart.items.destroy', $item))
        ->assertOk()
        ->assertJsonPath('data.totals.items_count', 0);
});

test('saving for later keeps the line and its chosen options', function () {
    $product = sellableProduct();
    $item = $this->postJson(route('api.customer.cart.items.store'), ['product_id' => $product->id])
        ->json('data.groups.0.items.0.id');

    $this->postJson(route('api.customer.cart.items.save', $item))
        ->assertOk()
        ->assertJsonPath('data.totals.items_count', 0)
        ->assertJsonCount(1, 'data.saved_for_later');

    $this->postJson(route('api.customer.cart.items.move', $item))
        ->assertOk()
        ->assertJsonPath('data.totals.items_count', 1);
});

test('a basket across two sellers is grouped, not merged', function () {
    $one = Vendor::factory()->create(['status' => 'approved', 'name' => 'Meera Textiles']);
    $two = Vendor::factory()->create(['status' => 'approved', 'name' => 'Sharma Home']);

    $this->postJson(route('api.customer.cart.items.store'), ['product_id' => sellableProduct([], $one)->id]);
    $response = $this->postJson(route('api.customer.cart.items.store'), ['product_id' => sellableProduct([], $two)->id]);

    $response->assertOk()->assertJsonCount(2, 'data.groups');

    expect(collect($response->json('data.groups'))->pluck('vendor.name'))
        ->toContain('Meera Textiles')->toContain('Sharma Home');
});

test('a flat coupon comes off the items', function () {
    Coupon::factory()->create(['code' => 'FIRST100', 'type' => 'flat', 'value' => 100, 'min_spend' => 999]);
    $this->postJson(route('api.customer.cart.items.store'), ['product_id' => sellableProduct(['price' => 1000])->id]);

    $this->postJson(route('api.customer.cart.coupon.apply'), ['code' => 'first100'])
        ->assertOk()
        ->assertJsonPath('data.coupon.code', 'FIRST100')
        ->assertJsonPath('data.totals.discount_total', 100)
        ->assertJsonPath('data.totals.grand_total', 900);
});

test('a coupon under its minimum is refused with the shortfall', function () {
    Coupon::factory()->create(['code' => 'BIG', 'type' => 'flat', 'value' => 500, 'min_spend' => 5000]);
    $this->postJson(route('api.customer.cart.items.store'), ['product_id' => sellableProduct(['price' => 1000])->id]);

    $this->postJson(route('api.customer.cart.coupon.apply'), ['code' => 'BIG'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');
});

test('an expired coupon is not a coupon', function () {
    Coupon::factory()->expired()->create(['code' => 'OLD']);
    $this->postJson(route('api.customer.cart.items.store'), ['product_id' => sellableProduct()->id]);

    $this->postJson(route('api.customer.cart.coupon.apply'), ['code' => 'OLD'])->assertStatus(422);
});

test('the coupon list says what each one is worth on this basket', function () {
    Coupon::factory()->create(['code' => 'USABLE', 'type' => 'flat', 'value' => 100, 'min_spend' => 500]);
    Coupon::factory()->create(['code' => 'TOOBIG', 'type' => 'flat', 'value' => 100, 'min_spend' => 50000]);

    $this->postJson(route('api.customer.cart.items.store'), ['product_id' => sellableProduct(['price' => 1000])->id]);

    $coupons = collect($this->getJson(route('api.customer.cart.coupons'))->json('data'))->keyBy('code');

    expect($coupons['USABLE']['usable'])->toBeTrue()
        ->and((float) $coupons['USABLE']['discount'])->toBe(100.0)
        ->and($coupons['TOOBIG']['usable'])->toBeFalse()
        ->and($coupons['TOOBIG']['reason'])->toContain('more to use');
});

test('a percentage coupon is taxed on what is actually paid', function () {
    Coupon::factory()->percent(50)->create(['code' => 'HALF']);
    $this->postJson(route('api.customer.cart.items.store'), ['product_id' => sellableProduct(['price' => 1000])->id]);
    $this->postJson(route('api.customer.cart.coupon.apply'), ['code' => 'HALF']);

    $this->getJson(route('api.customer.cart'))
        ->assertJsonPath('data.totals.discount_total', 500)
        ->assertJsonPath('data.totals.grand_total', 500);
});

test('one shopper cannot touch another shopper cart line', function () {
    $product = sellableProduct();
    $item = $this->postJson(route('api.customer.cart.items.store'), ['product_id' => $product->id])
        ->json('data.groups.0.items.0.id');

    actingAsCustomer();

    $this->patchJson(route('api.customer.cart.items.update', $item), ['quantity' => 9])->assertNotFound();
});

test('a free-shipping coupon is worth the delivery it covers', function () {
    $zone = ShippingZone::create(['name' => 'TN', 'states' => ['Tamil Nadu'], 'is_active' => true, 'position' => 0]);
    ShippingRate::create([
        'shipping_zone_id' => $zone->id, 'name' => 'Standard delivery', 'type' => 'flat',
        'rate' => 59, 'free_above_amount' => 999, 'delivery_days_min' => 4, 'delivery_days_max' => 7,
        'is_active' => true, 'position' => 0,
    ]);
    $this->customer->addresses()->create([
        'first_name' => 'Priya', 'address_line1' => '42 Beach Road', 'city' => 'Chennai',
        'state' => 'Tamil Nadu', 'postcode' => '600090', 'country' => 'IN', 'is_default_shipping' => true,
    ]);

    Coupon::factory()->freeShipping()->create(['code' => 'FREESHIP', 'min_spend' => 499]);
    $this->postJson(route('api.customer.cart.items.store'), ['product_id' => sellableProduct(['price' => 500])->id]);

    $this->postJson(route('api.customer.cart.coupon.apply'), ['code' => 'FREESHIP'])
        ->assertOk()
        ->assertJsonPath('data.coupon.shipping_discount', 59)
        ->assertJsonPath('data.coupon.savings', 59)
        ->assertJsonPath('data.totals.shipping_total', 0)
        ->assertJsonPath('data.totals.shipping_full_total', 59);
});

test('a coupon that would take nothing off is refused with the reason', function () {
    $zone = ShippingZone::create(['name' => 'TN', 'states' => ['Tamil Nadu'], 'is_active' => true, 'position' => 0]);
    ShippingRate::create([
        'shipping_zone_id' => $zone->id, 'name' => 'Standard delivery', 'type' => 'flat',
        'rate' => 59, 'free_above_amount' => 999, 'is_active' => true, 'position' => 0,
    ]);
    $this->customer->addresses()->create([
        'first_name' => 'Priya', 'address_line1' => '42 Beach Road', 'city' => 'Chennai',
        'state' => 'Tamil Nadu', 'postcode' => '600090', 'country' => 'IN', 'is_default_shipping' => true,
    ]);

    // Over the free-delivery threshold, so free shipping is already the price.
    Coupon::factory()->freeShipping()->create(['code' => 'FREESHIP', 'min_spend' => 499]);
    $this->postJson(route('api.customer.cart.items.store'), ['product_id' => sellableProduct(['price' => 1200])->id]);

    $this->postJson(route('api.customer.cart.coupon.apply'), ['code' => 'FREESHIP'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');

    // And the sheet greys it out rather than inviting the tap.
    $row = collect($this->getJson(route('api.customer.cart.coupons'))->json('data'))->firstWhere('code', 'FREESHIP');

    expect($row['usable'])->toBeFalse()
        ->and((float) $row['savings'])->toBe(0.0)
        ->and($row['reason'])->toContain('already free');
});

test('a rate outside the basket value band is not offered', function () {
    $zone = ShippingZone::create(['name' => 'TN', 'states' => ['Tamil Nadu'], 'is_active' => true, 'position' => 0]);
    ShippingRate::create([
        'shipping_zone_id' => $zone->id, 'name' => 'Small basket courier', 'type' => 'flat',
        'rate' => 40, 'max_order_amount' => 499, 'is_active' => true, 'position' => 0,
    ]);
    ShippingRate::create([
        'shipping_zone_id' => $zone->id, 'name' => 'Standard delivery', 'type' => 'flat',
        'rate' => 59, 'is_active' => true, 'position' => 1,
    ]);
    $this->customer->addresses()->create([
        'first_name' => 'Priya', 'address_line1' => '42 Beach Road', 'city' => 'Chennai',
        'state' => 'Tamil Nadu', 'postcode' => '600090', 'country' => 'IN', 'is_default_shipping' => true,
    ]);

    $this->postJson(route('api.customer.cart.items.store'), ['product_id' => sellableProduct(['price' => 1200])->id]);

    $codes = collect($this->getJson(route('api.customer.cart'))->json('data.shipping_options'))->pluck('code');

    expect($codes)->toContain('standard-delivery')->not->toContain('small-basket-courier');
});

test('a weight-based rate charges its base plus the weight, not every column', function () {
    $zone = ShippingZone::create(['name' => 'TN', 'states' => ['Tamil Nadu'], 'is_active' => true, 'position' => 0]);
    ShippingRate::create([
        'shipping_zone_id' => $zone->id, 'name' => 'Heavy items', 'type' => 'weight_based',
        'rate' => 99, 'per_kg_rate' => 20, 'per_item_rate' => 15, 'is_active' => true, 'position' => 0,
    ]);
    $this->customer->addresses()->create([
        'first_name' => 'Priya', 'address_line1' => '42 Beach Road', 'city' => 'Chennai',
        'state' => 'Tamil Nadu', 'postcode' => '600090', 'country' => 'IN', 'is_default_shipping' => true,
    ]);

    $this->postJson(route('api.customer.cart.items.store'), [
        'product_id' => sellableProduct(['price' => 1000, 'weight' => 2])->id,
        'quantity' => 2,
    ]);

    // 99 base + 20/kg × 4kg. The per-item column belongs to `item_based` and
    // must not be added on top.
    expect($this->getJson(route('api.customer.cart'))->json('data.shipping_options.0.rate'))->toEqual(179.0);
});
