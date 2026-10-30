<?php

use App\Actions\CreateManualOrder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayout;
use App\Notifications\ProductAwaitingPrice;
use App\Notifications\ProductWentLive;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Seller names the cost, the marketplace names the price
|--------------------------------------------------------------------------
|
| A seller's product carries what the seller wants paid per unit. Only staff
| set what a shopper pays and put it on sale; the seller is then paid their
| cost on every unit sold and the marketplace keeps the difference.
|
*/

/**
 * @return array{0: User, 1: Vendor}
 */
function costSellerOnPanel(): array
{
    $vendor = Vendor::factory()->create(['status' => 'approved']);

    $user = User::factory()->create([
        'role' => 'vendor',
        'vendor_id' => $vendor->id,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    test()->actingAs($user);

    return [$user, $vendor];
}

/**
 * Everything the admin product form posts, for an existing product.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function adminProductPayload(Product $product, array $overrides = []): array
{
    return [
        'name' => $product->name,
        'type' => $product->type,
        'vendor_id' => $product->vendor_id,
        'price' => (float) $product->price,
        'cost_price' => $product->cost_price,
        'status' => $product->status,
        'track_inventory' => true,
        'stock_quantity' => $product->stock_quantity,
        ...$overrides,
    ];
}

test('a seller sets a cost, never a price, and the product waits for the marketplace', function () {
    Notification::fake();
    $admin = adminUser();
    [, $store] = costSellerOnPanel();

    $this->post(route('seller.products.store'), [
        'name' => 'Hand-loom saree',
        'type' => 'simple',
        'cost_price' => 1800,
        // Neither of these is the seller's to set.
        'price' => 2499,
        'compare_at_price' => 2999,
        'status' => 'active',
        'track_inventory' => true,
        'stock_quantity' => 5,
    ])->assertRedirect();

    $product = Product::firstWhere('name', 'Hand-loom saree');

    expect($product->vendor_id)->toBe($store->id)
        ->and((float) $product->cost_price)->toBe(1800.0)
        ->and((float) $product->price)->toBe(0.0)
        ->and($product->compare_at_price)->toBeNull()
        ->and($product->status)->toBe('pending');

    Notification::assertSentTo($admin, ProductAwaitingPrice::class,
        fn (ProductAwaitingPrice $n) => $n->title() === 'Hand-loom saree needs a price'
            && $n->url() === "/admin/products/{$product->id}/edit");
});

test('a pending product never reaches the shop', function () {
    $product = sellableProduct(['status' => 'pending']);

    $this->getJson(route('api.customer.products.show', $product->slug))->assertNotFound();

    $product->update(['status' => 'active']);

    $this->getJson(route('api.customer.products.show', $product->slug))->assertOk();
});

test('a live product stays live through an edit that leaves the cost alone', function () {
    [, $store] = costSellerOnPanel();
    $product = Product::factory()->for($store)->create([
        'status' => 'active', 'price' => 2499, 'cost_price' => 1800,
    ]);

    $this->put(route('seller.products.update', $product), [
        'name' => 'Hand-loom saree, now with a better name',
        'type' => 'simple',
        'cost_price' => '1800.00',
        'status' => 'active',
        'stock_quantity' => 9,
    ])->assertRedirect();

    expect($product->fresh())
        ->status->toBe('active')
        ->name->toBe('Hand-loom saree, now with a better name')
        ->and((float) $product->fresh()->price)->toBe(2499.0);
});

test('changing the cost takes a live product off sale until it is re-priced', function () {
    Notification::fake();
    $admin = adminUser();
    [, $store] = costSellerOnPanel();
    $product = Product::factory()->for($store)->create([
        'status' => 'active', 'price' => 2499, 'cost_price' => 1800,
    ]);

    $this->put(route('seller.products.update', $product), [
        'name' => $product->name,
        'type' => 'simple',
        'cost_price' => 2200,
        'status' => 'active',
        'stock_quantity' => 9,
    ])->assertRedirect()->assertSessionHas('success', fn ($m) => str_contains($m, 'off sale'));

    // The old price is kept for staff to start from, but nobody can buy at it.
    expect($product->fresh())
        ->status->toBe('pending')
        ->and((float) $product->fresh()->price)->toBe(2499.0)
        ->and((float) $product->fresh()->cost_price)->toBe(2200.0);

    Notification::assertSentTo($admin, ProductAwaitingPrice::class);
});

test('a variant whose cost moves, or a new variant, sends a live product back too', function () {
    [, $store] = actingAsSeller();
    $product = Product::factory()->for($store)->create(['type' => 'variable', 'status' => 'active', 'price' => 999]);
    $small = $product->variants()->create(['name' => 'Small', 'price' => 999, 'cost_price' => 600, 'stock_quantity' => 3]);

    $payload = fn (array $variants) => [
        'name' => $product->name,
        'type' => 'variable',
        'status' => 'active',
        'variants' => $variants,
    ];

    // Same cost: stays live, and the marketplace's price on it is untouched.
    $this->putJson(route('api.seller.products.update', $product->id), $payload([
        ['id' => $small->id, 'name' => 'Small', 'cost_price' => 600, 'stock_quantity' => 8],
    ]))->assertOk()->assertJsonPath('data.status', 'active');

    expect((float) $small->fresh()->price)->toBe(999.0)
        ->and($small->fresh()->stock_quantity)->toBe(8);

    // A new variant has never been priced.
    $this->putJson(route('api.seller.products.update', $product->id), $payload([
        ['id' => $small->id, 'name' => 'Small', 'cost_price' => 600, 'stock_quantity' => 8],
        ['name' => 'Large', 'cost_price' => 700, 'stock_quantity' => 2],
    ]))->assertOk()->assertJsonPath('data.status', 'pending');

    expect((float) $product->variants()->firstWhere('name', 'Large')->price)->toBe(0.0);

    // Back on sale, then a cost change on an existing variant.
    $product->forceFill(['status' => 'active'])->save();
    $large = $product->variants()->firstWhere('name', 'Large');

    $this->putJson(route('api.seller.products.update', $product->id), $payload([
        ['id' => $small->id, 'name' => 'Small', 'cost_price' => 650, 'stock_quantity' => 8],
        ['id' => $large->id, 'name' => 'Large', 'cost_price' => 700, 'stock_quantity' => 2],
    ]))->assertOk()->assertJsonPath('data.status', 'pending');
});

test('staff cannot put a product on sale at zero', function () {
    actingAsAdmin();
    $product = Product::factory()->create(['status' => 'pending', 'price' => 0, 'cost_price' => 1800]);

    $this->put(route('admin.products.update', $product), adminProductPayload($product, ['status' => 'active']))
        ->assertSessionHasErrors(['price']);

    expect($product->fresh()->status)->toBe('pending');
});

test('staff price a seller\'s product and the seller hears it is live', function () {
    Notification::fake();
    $admin = actingAsAdmin();
    [$seller, $store] = costSellerOnPanel();
    $this->actingAs($admin);

    $product = Product::factory()->for($store)->create([
        'status' => 'pending', 'price' => 0, 'cost_price' => 1800, 'published_at' => null,
    ]);

    $this->put(route('admin.products.update', $product), adminProductPayload($product, [
        'price' => 2499,
        'compare_at_price' => 2999,
        'status' => 'active',
    ]))->assertSessionHasNoErrors();

    expect($product->fresh())
        ->status->toBe('active')
        ->published_at->not->toBeNull()
        ->and((float) $product->fresh()->price)->toBe(2499.0);

    Notification::assertSentTo($seller, ProductWentLive::class,
        fn (ProductWentLive $n) => $n->body() === 'Shoppers now see it at ₹2,499.00.');
});

test('bulk activate leaves anything still unpriced where it is', function () {
    actingAsAdmin();
    $priced = Product::factory()->create(['status' => 'pending', 'price' => 2499]);
    $unpriced = Product::factory()->create(['status' => 'pending', 'price' => 0]);
    $variantUnpriced = Product::factory()->create(['status' => 'pending', 'price' => 999, 'type' => 'variable']);
    $variantUnpriced->variants()->create(['name' => 'Large', 'price' => 0, 'cost_price' => 700, 'is_active' => true]);

    $this->post(route('admin.products.bulk'), [
        'action' => 'activate',
        'ids' => [$priced->id, $unpriced->id, $variantUnpriced->id],
    ])->assertSessionHas('success', '1 product(s) activated. 2 skipped — they still need a price.');

    expect($priced->fresh()->status)->toBe('active')
        ->and($unpriced->fresh()->status)->toBe('pending')
        ->and($variantUnpriced->fresh()->status)->toBe('pending');
});

test('a product that came off sale cannot be checked out from a basket', function () {
    $customer = actingAsCustomer();
    $address = $customer->addresses()->create([
        'first_name' => 'Priya', 'last_name' => 'Nair', 'address_line1' => '42 Beach Road',
        'city' => 'Chennai', 'state' => 'Tamil Nadu', 'postcode' => '600090', 'country' => 'IN',
        'phone' => '9876543210', 'is_default_shipping' => true,
    ]);

    $product = sellableProduct(['name' => 'Hand-loom saree']);
    $this->postJson(route('api.customer.cart.items.store'), ['product_id' => $product->id, 'quantity' => 1])->assertOk();

    // The seller changed the cost after it went in the basket.
    $product->update(['status' => 'pending']);

    $this->postJson(route('api.customer.orders.store'), [
        'address_id' => $address->id,
        'payment_method' => 'upi',
    ])->assertStatus(422)->assertJsonValidationErrors(['items']);

    expect(Order::count())->toBe(0);
});

test('the seller is paid their cost and the marketplace carries the coupon', function () {
    // 2 units at 1000, cost 700 each, after a coupon that took 10% off.
    expect(CreateManualOrder::splitFor(1800.0, 700.0, 2, 10.0))->toBe([
        'commission_rate' => 22.22,
        'commission_amount' => 400.0,
        'vendor_earning' => 1400.0,
    ]);

    // No cost on file: the store's rate comes off what was paid.
    expect(CreateManualOrder::splitFor(1800.0, null, 2, 10.0))->toBe([
        'commission_rate' => 10.0,
        'commission_amount' => 180.0,
        'vendor_earning' => 1620.0,
    ]);
});

test('a payout pays the earning booked on each line, not the pre-coupon total', function () {
    actingAsAdmin();
    $vendor = Vendor::factory()->create();

    $order = Order::factory()->create(['placed_at' => now()->subDay(), 'status' => 'completed']);
    OrderItem::factory()->forOrder($order)->create([
        'vendor_id' => $vendor->id,
        'total' => 2000,
        'discount_amount' => 200,
        'commission_amount' => 400,
        'vendor_earning' => 1400,
    ]);

    $this->post(route('admin.payouts.store'), [
        'vendor_id' => $vendor->id,
        'period_start' => now()->subDays(7)->toDateString(),
        'period_end' => now()->toDateString(),
    ])->assertSessionHas('success');

    expect(VendorPayout::firstOrFail())
        ->and((float) VendorPayout::first()->gross_sales)->toBe(1800.0)
        ->and((float) VendorPayout::first()->commission_amount)->toBe(400.0)
        ->and((float) VendorPayout::first()->net_amount)->toBe(1400.0);
});
