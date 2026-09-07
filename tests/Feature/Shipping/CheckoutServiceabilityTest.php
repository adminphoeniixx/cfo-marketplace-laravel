<?php

use App\Models\DeliveryPartner;
use App\Models\PaymentMethod;
use App\Models\Vendor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
| The courier's answer, where the shopper can still act on it.
|
| Two refusals meet at this screen and they are not the same refusal: a seller
| who will not take cash, and a pincode the courier will not collect it from.
| The first is the shopper's to solve by shopping elsewhere and is named first;
| the second is the pincode's, and no amount of choosing a different seller
| changes it.
|
| Everything here is also a test of the fail-open rule. A courier having a bad
| morning must leave the checkout exactly as it was.
*/

beforeEach(function () {
    $this->customer = actingAsCustomer();

    $this->address = $this->customer->addresses()->create([
        'label' => 'Home',
        'first_name' => 'Priya', 'last_name' => 'Nair',
        'address_line1' => '42 Beach Road', 'city' => 'Chennai',
        'state' => 'Tamil Nadu', 'postcode' => '600090',
        'country' => 'IN', 'phone' => '9876543210',
        'is_default_shipping' => true,
    ]);

    PaymentMethod::query()->whereNotIn('code', ['upi', 'cash-on-delivery'])->update(['is_active' => false]);

    config([
        'services.delhivery.token' => 'test-token',
        'services.delhivery.base_url' => 'https://staging-express.delhivery.com',
        'services.delhivery.pickup_name' => 'CFO Noida',
    ]);

    DeliveryPartner::where('name', 'Delhivery')->update(['driver' => 'delhivery', 'is_active' => true]);

    Cache::flush();
});

/** Delhivery's shape for a pincode, on whatever terms. */
function servedAs(string $cod): array
{
    return ['delivery_codes' => [['postal_code' => [
        'pin' => 600090, 'cod' => $cod, 'pre_paid' => 'Y', 'pickup' => 'Y',
    ]]]];
}

function cartWithOne(): void
{
    $vendor = Vendor::factory()->create(['status' => 'approved', 'cod_available' => true]);

    test()->postJson(route('api.customer.cart.items.store'), [
        'product_id' => sellableProduct(['price' => 1000], $vendor)->id,
        'quantity' => 1,
    ])->assertOk();
}

test('the review screen says the pincode is served and cash is fine', function () {
    Http::fake(['*/c/api/pin-codes/json/*' => Http::response(servedAs('Y'))]);
    cartWithOne();

    $response = $this->getJson(route('api.customer.checkout'))->assertOk();

    expect($response->json('delivery'))->toMatchArray([
        'serviceable' => true, 'cod_available' => true, 'checked' => true, 'carrier' => 'Delhivery',
    ]);

    $cash = collect($response->json('payment_methods'))->firstWhere('code', 'cash-on-delivery');

    expect($cash['is_available'])->toBeTrue();
});

test('a prepaid-only pincode greys cash out and says whose refusal it is', function () {
    Http::fake(['*/c/api/pin-codes/json/*' => Http::response(servedAs('N'))]);
    cartWithOne();

    $response = $this->getJson(route('api.customer.checkout'))->assertOk();

    expect($response->json('delivery.cod_available'))->toBeFalse();

    $cash = collect($response->json('payment_methods'))->firstWhere('code', 'cash-on-delivery');

    expect($cash['is_available'])->toBeFalse()
        ->and($cash['unavailable_reason'])->toBe('Cash on delivery is not available for this pincode.');

    // And the other way to pay is untouched — this closes one door, not the shop.
    $upi = collect($response->json('payment_methods'))->firstWhere('code', 'upi');
    expect($upi['is_available'])->toBeTrue();
});

test('cash on a prepaid-only pincode is refused rather than booked and failed later', function () {
    Http::fake(['*/c/api/pin-codes/json/*' => Http::response(servedAs('N'))]);
    cartWithOne();

    $this->postJson(route('api.customer.orders.store'), [
        'address_id' => $this->address->id,
        'payment_method' => 'cash-on-delivery',
    ])
        ->assertStatus(422)
        ->assertJsonPath('errors.payment_method.0', 'Cash on delivery is not available for this pincode. Choose another way to pay.');
});

test('the same basket goes through prepaid', function () {
    Http::fake(['*/c/api/pin-codes/json/*' => Http::response(servedAs('N'))]);
    cartWithOne();

    $this->postJson(route('api.customer.orders.store'), [
        'address_id' => $this->address->id,
        'payment_method' => 'upi',
    ])->assertCreated();
});

test('a seller refusing cash is named ahead of the courier', function () {
    Http::fake(['*/c/api/pin-codes/json/*' => Http::response(servedAs('N'))]);

    $vendor = Vendor::factory()->create([
        'status' => 'approved', 'name' => 'Woodline Furniture', 'cod_available' => false,
    ]);

    $this->postJson(route('api.customer.cart.items.store'), [
        'product_id' => sellableProduct(['price' => 1000], $vendor)->id,
        'quantity' => 1,
    ])->assertOk();

    $cash = collect($this->getJson(route('api.customer.checkout'))->json('payment_methods'))
        ->firstWhere('code', 'cash-on-delivery');

    // Both are refusing. The seller's is the one a shopper can act on.
    expect($cash['unavailable_reason'])->toBe('Woodline Furniture does not take cash on delivery');
});

test('an unreachable courier changes nothing about the checkout', function () {
    Http::fake(fn () => throw new ConnectionException('Delhivery is having a morning.'));
    cartWithOne();

    $response = $this->getJson(route('api.customer.checkout'))->assertOk();

    expect($response->json('delivery.checked'))->toBeFalse();

    $cash = collect($response->json('payment_methods'))->firstWhere('code', 'cash-on-delivery');
    expect($cash['is_available'])->toBeTrue();

    $this->postJson(route('api.customer.orders.store'), [
        'address_id' => $this->address->id,
        'payment_method' => 'cash-on-delivery',
    ])->assertCreated();
});

test('a pincode nobody serves is not told to pay another way', function () {
    Http::fake(['*/c/api/pin-codes/json/*' => Http::response(['delivery_codes' => []])]);
    cartWithOne();

    $this->postJson(route('api.customer.orders.store'), [
        'address_id' => $this->address->id,
        'payment_method' => 'cash-on-delivery',
    ])
        ->assertStatus(422)
        // No "choose another way": there is no way to pay that makes a parcel
        // reach an address no courier goes to.
        ->assertJsonPath('errors.payment_method.0', 'No courier delivers to this pincode yet.');
});
