<?php

use App\Models\Customer;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\Http;

test('a phone number and a code are enough to get in', function () {
    $issued = $this->postJson(route('api.customer.auth.otp'), [
        'phone' => '+91 98765 43210',
    ])->assertOk();

    $code = $issued->json('debug_code');
    expect($code)->toBeString();

    $response = $this->postJson(route('api.customer.auth.otp.verify'), [
        'phone' => '9876543210',
        'code' => $code,
        'device_name' => 'Pixel 8',
        'first_name' => 'Priya',
    ])->assertOk();

    expect($response->json('token'))->toBeString()->not->toBeEmpty();
    $response->assertJsonPath('customer.first_name', 'Priya')
        ->assertJsonPath('customer.phone_verified', true);

    // Signing in for the first time is what creates the account.
    expect(Customer::where('phone', '9876543210')->count())->toBe(1);
});

test('the same phone in two shapes is the same shopper', function () {
    $customer = Customer::factory()->create(['phone' => '9876543210']);

    $code = $this->postJson(route('api.customer.auth.otp'), ['phone' => '+919876543210'])->json('debug_code');

    $this->postJson(route('api.customer.auth.otp.verify'), [
        'phone' => '098765 43210',
        'code' => $code,
        'device_name' => 'Pixel',
    ])->assertOk()->assertJsonPath('customer.id', $customer->id);

    expect(Customer::count())->toBe(1);
});

test('a wrong code is refused and a spent code cannot be reused', function () {
    $code = $this->postJson(route('api.customer.auth.otp'), ['phone' => '9876543210'])->json('debug_code');

    $this->postJson(route('api.customer.auth.otp.verify'), [
        'phone' => '9876543210',
        'code' => '000000',
        'device_name' => 'Pixel',
    ])->assertStatus(422);

    $this->postJson(route('api.customer.auth.otp.verify'), [
        'phone' => '9876543210',
        'code' => $code,
        'device_name' => 'Pixel',
    ])->assertOk();

    // The code is burned the moment it works.
    $this->postJson(route('api.customer.auth.otp.verify'), [
        'phone' => '9876543210',
        'code' => $code,
        'device_name' => 'Pixel',
    ])->assertStatus(422);
});

test('an email and password sign in works too', function () {
    Customer::factory()->withPassword()->create(['email' => 'priya@example.com']);

    $this->postJson(route('api.customer.auth.login'), [
        'email' => 'priya@example.com',
        'password' => 'a-good-password',
        'device_name' => 'Pixel',
    ])->assertOk()->assertJsonPath('customer.email', 'priya@example.com');

    $this->postJson(route('api.customer.auth.login'), [
        'email' => 'priya@example.com',
        'password' => 'wrong',
        'device_name' => 'Pixel',
    ])->assertStatus(422);
});

test('signing in again on the same device replaces that device token', function () {
    $customer = Customer::factory()->withPassword()->create(['email' => 'priya@example.com']);

    foreach (range(1, 2) as $ignored) {
        $this->postJson(route('api.customer.auth.login'), [
            'email' => 'priya@example.com',
            'password' => 'a-good-password',
            'device_name' => 'Pixel 8',
        ])->assertOk();
    }

    expect($customer->tokens()->count())->toBe(1);
});

test('the shop is open to browse and shut to everything else', function () {
    // Browsing needs no token at all.
    $this->getJson(route('api.customer.products'))->assertOk();
    $this->getJson(route('api.customer.home'))->assertOk();
    $this->getJson(route('api.customer.reference'))->assertOk();

    // Everything that is someone's own does.
    $this->getJson(route('api.customer.me'))->assertUnauthorized();
    $this->getJson(route('api.customer.cart'))->assertUnauthorized();
    $this->getJson(route('api.customer.orders.index'))->assertUnauthorized();
    $this->postJson(route('api.customer.wishlist.store'), ['product_id' => 1])->assertUnauthorized();
});

test('a blocked shopper keeps their token but cannot shop', function () {
    actingAsCustomer(['status' => 'blocked']);

    $this->getJson(route('api.customer.me'))->assertForbidden();
});

test("a seller's token is not a shopper's token", function () {
    $vendor = Vendor::factory()->create(['status' => 'approved']);
    $seller = User::factory()->create(['role' => 'vendor', 'vendor_id' => $vendor->id]);

    $this->withHeader('Authorization', 'Bearer '.$seller->createToken('phone')->plainTextToken)
        ->getJson(route('api.customer.me'))
        ->assertForbidden();
});

test('signing out drops only this device', function () {
    $customer = actingAsCustomer();
    $customer->createToken('another phone');

    $this->postJson(route('api.customer.auth.logout'))->assertOk();

    expect($customer->tokens()->count())->toBe(1);
});

test('signing out everywhere drops the lot', function () {
    $customer = actingAsCustomer();
    $customer->createToken('another phone');

    $this->postJson(route('api.customer.auth.logout-all'))->assertOk();

    expect($customer->tokens()->count())->toBe(0);
});

test('the account screen can list and revoke devices', function () {
    $customer = actingAsCustomer();
    $other = $customer->createToken('iPad')->accessToken;

    $this->getJson(route('api.customer.auth.devices'))
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->deleteJson(route('api.customer.auth.devices.revoke', $other->id))->assertOk();

    expect($customer->tokens()->count())->toBe(1);
});

test('a configured provider sends the code and stops handing it back', function () {
    config([
        'services.sms.driver' => 'http',
        'services.sms.key' => 'sms-key',
        'services.sms.url' => 'https://control.msg91.com/api/v5/flow',
        'services.sms.template_id' => 'tpl_1',
    ]);

    Http::fake(['control.msg91.com/*' => Http::response(['type' => 'success'])]);

    $response = $this->postJson(route('api.customer.auth.otp'), ['phone' => '9876543210'])
        ->assertOk()
        ->assertJsonPath('delivered', true);

    // The whole point of an SMS is that the code is not in the response.
    expect($response->json('debug_code'))->toBeNull();

    Http::assertSent(function ($request) {
        // Bare Indian numbers are stored without a country code; providers
        // want one.
        return $request['mobiles'] === '919876543210' && $request['otp'] !== null;
    });
});

test('a provider having a bad morning does not break the sign-in screen', function () {
    config([
        'services.sms.driver' => 'http',
        'services.sms.key' => 'sms-key',
        'services.sms.url' => 'https://control.msg91.com/api/v5/flow',
    ]);

    Http::fake(['control.msg91.com/*' => Http::response(['message' => 'nope'], 500)]);

    // `sent` stays true: telling a caller which numbers are registered is a
    // gift to whoever is probing, and the failure is in the log instead.
    $this->postJson(route('api.customer.auth.otp'), ['phone' => '9876543210'])
        ->assertOk()
        ->assertJsonPath('sent', true)
        ->assertJsonPath('delivered', false);
});
