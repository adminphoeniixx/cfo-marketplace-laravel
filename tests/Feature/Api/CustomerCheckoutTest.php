<?php

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\Vendor;

beforeEach(function () {
    $this->customer = actingAsCustomer();

    $this->address = $this->customer->addresses()->create([
        'label' => 'Home',
        'first_name' => 'Priya',
        'last_name' => 'Nair',
        'address_line1' => '42 Beach Road',
        'city' => 'Chennai',
        'state' => 'Tamil Nadu',
        'postcode' => '600090',
        'country' => 'IN',
        'phone' => '9876543210',
        'is_default_shipping' => true,
    ]);

    // The payment-method list ships with the schema; the app picks from it
    // rather than inventing codes.
    PaymentMethod::query()->whereNotIn('code', ['upi', 'cash-on-delivery'])->update(['is_active' => false]);
});

function addToCart(int $productId, int $quantity = 1): void
{
    test()->postJson(route('api.customer.cart.items.store'), [
        'product_id' => $productId,
        'quantity' => $quantity,
    ])->assertOk();
}

test('the checkout screen quotes the same total the cart did', function () {
    addToCart(sellableProduct(['price' => 1000])->id, 2);

    $cart = $this->getJson(route('api.customer.cart'))->json('data.totals.grand_total');

    $this->getJson(route('api.customer.checkout'))
        ->assertOk()
        ->assertJsonPath('totals.grand_total', $cart)
        ->assertJsonPath('selected_address_id', $this->address->id)
        ->assertJsonCount(2, 'payment_methods');
});

test('placing an order empties the basket and writes the money', function () {
    $vendor = Vendor::factory()->create(['status' => 'approved', 'commission_rate' => 10]);
    addToCart(sellableProduct(['price' => 1000], $vendor)->id, 2);

    $response = $this->postJson(route('api.customer.orders.store'), [
        'address_id' => $this->address->id,
        'payment_method' => 'upi',
    ])->assertCreated();

    $order = Order::firstOrFail();

    expect((float) $order->subtotal)->toBe(2000.0)
        ->and((float) $order->grand_total)->toBe(2000.0)
        ->and($order->customer_id)->toBe($this->customer->id)
        ->and($order->payment_method)->toBe('UPI')
        ->and($order->payment_status)->toBe('paid')
        ->and($order->status)->toBe('processing')
        // Commission is the marketplace's business, not the shopper's.
        ->and((float) $order->commission_total)->toBe(200.0)
        ->and((float) $order->items->first()->vendor_earning)->toBe(1800.0);

    $response->assertJsonPath('data.number', $order->number)
        ->assertJsonMissingPath('data.items.0.commission_amount');

    $this->getJson(route('api.customer.cart'))->assertJsonPath('data.totals.items_count', 0);
});

test('cash on delivery is not paid yet', function () {
    addToCart(sellableProduct()->id);

    $this->postJson(route('api.customer.orders.store'), [
        'address_id' => $this->address->id,
        'payment_method' => 'cash-on-delivery',
    ])->assertCreated();

    $order = Order::firstOrFail();

    expect($order->payment_status)->toBe('pending')
        ->and($order->paid_at)->toBeNull();
});

test('one basket across two sellers is one order with two sets of lines', function () {
    $one = Vendor::factory()->create(['status' => 'approved', 'commission_rate' => 10]);
    $two = Vendor::factory()->create(['status' => 'approved', 'commission_rate' => 20]);

    addToCart(sellableProduct(['price' => 1000], $one)->id);
    addToCart(sellableProduct(['price' => 2000], $two)->id);

    $this->postJson(route('api.customer.orders.store'), [
        'address_id' => $this->address->id,
        'payment_method' => 'upi',
    ])->assertCreated();

    $order = Order::with('items')->firstOrFail();

    expect(Order::count())->toBe(1)
        ->and($order->items)->toHaveCount(2)
        // Each line is charged its own store's rate.
        ->and((float) $order->items->firstWhere('vendor_id', $one->id)->commission_amount)->toBe(100.0)
        ->and((float) $order->items->firstWhere('vendor_id', $two->id)->commission_amount)->toBe(400.0);
});

test('stock comes down when the order goes in', function () {
    $product = sellableProduct(['stock_quantity' => 5]);
    addToCart($product->id, 2);

    $this->postJson(route('api.customer.orders.store'), [
        'address_id' => $this->address->id,
        'payment_method' => 'upi',
    ])->assertCreated();

    expect($product->fresh()->stock_quantity)->toBe(3);
});

test('an order is refused when the shelf emptied while the app sat open', function () {
    $product = sellableProduct(['stock_quantity' => 5]);
    addToCart($product->id, 3);

    $product->update(['stock_quantity' => 1]);

    $this->postJson(route('api.customer.orders.store'), [
        'address_id' => $this->address->id,
        'payment_method' => 'upi',
    ])->assertStatus(422)->assertJsonValidationErrors('items');

    expect(Order::count())->toBe(0)
        ->and($product->fresh()->stock_quantity)->toBe(1);
});

test('a coupon is carried onto the order and counted', function () {
    $coupon = Coupon::factory()->create(['code' => 'FIRST100', 'value' => 100, 'min_spend' => 500]);
    addToCart(sellableProduct(['price' => 1000])->id);

    $this->postJson(route('api.customer.cart.coupon.apply'), ['code' => 'FIRST100'])->assertOk();

    $this->postJson(route('api.customer.orders.store'), [
        'address_id' => $this->address->id,
        'payment_method' => 'upi',
    ])->assertCreated();

    $order = Order::firstOrFail();

    expect($order->coupon_code)->toBe('FIRST100')
        ->and((float) $order->discount_total)->toBe(100.0)
        ->and((float) $order->grand_total)->toBe(900.0)
        ->and($coupon->fresh()->used_count)->toBe(1);
});

test('another shopper address cannot be delivered to', function () {
    $stranger = Customer::factory()->create();
    $theirs = $stranger->addresses()->create([
        'first_name' => 'Someone', 'address_line1' => 'Elsewhere', 'city' => 'Pune', 'country' => 'IN',
    ]);

    addToCart(sellableProduct()->id);

    $this->postJson(route('api.customer.orders.store'), [
        'address_id' => $theirs->id,
        'payment_method' => 'upi',
    ])->assertStatus(422)->assertJsonValidationErrors('address_id');
});

test('an empty basket cannot be checked out', function () {
    $this->postJson(route('api.customer.orders.store'), [
        'address_id' => $this->address->id,
        'payment_method' => 'upi',
    ])->assertStatus(422);
});

test('the seller hears about the order', function () {
    $vendor = Vendor::factory()->create(['status' => 'approved']);
    $seller = User::factory()->create([
        'role' => 'vendor', 'vendor_id' => $vendor->id, 'is_active' => true, 'email_verified_at' => now(),
    ]);

    addToCart(sellableProduct([], $vendor)->id);

    $this->postJson(route('api.customer.orders.store'), [
        'address_id' => $this->address->id,
        'payment_method' => 'upi',
    ])->assertCreated();

    expect($seller->unreadNotifications()->count())->toBeGreaterThan(0);
});

test('saved-for-later lines are not bought by accident', function () {
    $keep = sellableProduct(['price' => 1000]);
    $later = sellableProduct(['price' => 4000]);

    addToCart($keep->id);
    addToCart($later->id);

    $laterId = collect($this->getJson(route('api.customer.cart'))->json('data.groups'))
        ->flatMap(fn ($group) => $group['items'])
        ->firstWhere('product_id', $later->id)['id'];

    $this->postJson(route('api.customer.cart.items.save', $laterId))->assertOk();

    $this->postJson(route('api.customer.orders.store'), [
        'address_id' => $this->address->id,
        'payment_method' => 'upi',
    ])->assertCreated();

    $order = Order::with('items')->firstOrFail();

    expect($order->items)->toHaveCount(1)
        ->and($order->items->first()->product_id)->toBe($keep->id);

    // And it is still sitting there for next time.
    $this->getJson(route('api.customer.cart'))->assertJsonCount(1, 'data.saved_for_later');
});

test('a seller who will not take cash greys the option out and refuses the order', function () {
    $noCash = Vendor::factory()->create(['status' => 'approved', 'name' => 'Woodline Furniture', 'cod_available' => false]);
    addToCart(sellableProduct(['price' => 1000], $noCash)->id);

    $methods = collect($this->getJson(route('api.customer.checkout'))->assertOk()->json('payment_methods'))
        ->keyBy('code');

    // Greyed out rather than missing: an option that vanishes reads as a bug.
    expect($methods['cash-on-delivery']['is_available'])->toBeFalse()
        ->and($methods['cash-on-delivery']['unavailable_reason'])
        ->toBe('Woodline Furniture does not take cash on delivery')
        ->and($methods['upi']['is_available'])->toBeTrue();

    // And the screen is not the rule — an app that skipped it is still refused.
    $this->postJson(route('api.customer.orders.store'), [
        'address_id' => $this->address->id,
        'payment_method' => 'cash-on-delivery',
    ])->assertStatus(422)->assertJsonValidationErrors('payment_method');

    $this->postJson(route('api.customer.orders.store'), [
        'address_id' => $this->address->id,
        'payment_method' => 'upi',
    ])->assertCreated();
});

test('cash stays on offer where every seller in the basket takes it', function () {
    addToCart(sellableProduct(['price' => 1000])->id);

    $methods = collect($this->getJson(route('api.customer.checkout'))->json('payment_methods'))->keyBy('code');

    expect($methods['cash-on-delivery']['is_available'])->toBeTrue()
        ->and($methods['cash-on-delivery']['unavailable_reason'])->toBeNull();
});
