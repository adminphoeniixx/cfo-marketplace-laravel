<?php

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\Hash;

test('a seller can sign up and gets a token straight away', function () {
    $response = $this->postJson(route('api.seller.register'), [
        'name' => 'Meera Shah',
        'email' => 'meera@store.test',
        'password' => 'a-good-password',
        'password_confirmation' => 'a-good-password',
        'phone' => '9876543210',
        'store_name' => 'Meera Textiles',
        'device_name' => 'iPhone 15',
    ])->assertCreated();

    $response->assertJsonPath('seller.email', 'meera@store.test')
        ->assertJsonPath('seller.store.name', 'Meera Textiles')
        // Signing up does not let you trade.
        ->assertJsonPath('seller.store.status', 'pending')
        ->assertJsonPath('seller.store.can_trade', false);

    expect($response->json('token'))->toBeString()->not->toBeEmpty();

    $user = User::where('email', 'meera@store.test')->firstOrFail();

    expect($user->role)->toBe('vendor')
        ->and($user->vendor_id)->not->toBeNull()
        ->and($user->tokens()->count())->toBe(1);
});

test('signing up tells the marketplace a seller is waiting', function () {
    $watcher = adminUser();

    $this->postJson(route('api.seller.register'), [
        'name' => 'Meera',
        'email' => 'meera@store.test',
        'password' => 'a-good-password',
        'password_confirmation' => 'a-good-password',
        'store_name' => 'Meera Textiles',
        'device_name' => 'iPhone',
    ])->assertCreated();

    expect($watcher->unreadNotifications()->count())->toBe(1)
        ->and($watcher->unreadNotifications()->first()->data['kind'])->toBe('vendor-registered');
});

test('a pending seller can read their profile but cannot trade', function () {
    [$seller] = actingAsSeller(['status' => 'pending']);

    $this->getJson(route('api.seller.me'))
        ->assertOk()
        ->assertJsonPath('data.store.status', 'pending');

    $this->getJson(route('api.seller.products.index'))
        ->assertForbidden()
        ->assertJsonPath('store_status', 'pending');

    $this->getJson(route('api.seller.orders.index'))->assertForbidden();
});

test('a suspended store is told why it is shut out', function () {
    actingAsSeller(['status' => 'suspended']);

    $this->getJson(route('api.seller.products.index'))
        ->assertForbidden()
        ->assertJsonPath('store_status', 'suspended');
});

test('login returns a token and rejects bad credentials', function () {
    $vendor = Vendor::factory()->create(['status' => 'approved']);
    User::factory()->create([
        'email' => 'seller@store.test',
        'password' => Hash::make('a-good-password'),
        'role' => 'vendor',
        'vendor_id' => $vendor->id,
    ]);

    $this->postJson(route('api.seller.login'), [
        'email' => 'seller@store.test',
        'password' => 'a-good-password',
        'device_name' => 'Pixel 9',
    ])->assertOk()->assertJsonStructure(['token', 'seller' => ['id', 'store']]);

    $this->postJson(route('api.seller.login'), [
        'email' => 'seller@store.test',
        'password' => 'wrong',
        'device_name' => 'Pixel 9',
    ])->assertStatus(422);
});

test('an admin login cannot use the seller api', function () {
    $admin = adminUser();

    $this->postJson(route('api.seller.login'), [
        'email' => $admin->email,
        'password' => 'password',
        'device_name' => 'Laptop',
    ])->assertStatus(422);
});

test('a deactivated seller cannot sign in and an existing token stops working', function () {
    [$seller] = actingAsSeller();

    $this->getJson(route('api.seller.me'))->assertOk();

    $seller->forceFill(['is_active' => false])->save();

    // The guard caches the resolved user for the lifetime of the application,
    // which a single test shares across requests. Production gets a fresh app
    // per request; this reproduces that.
    $this->app['auth']->forgetGuards();

    $this->getJson(route('api.seller.me'))->assertForbidden();

    $this->postJson(route('api.seller.login'), [
        'email' => $seller->email,
        'password' => 'password',
        'device_name' => 'Pixel',
    ])->assertStatus(422);
});

test('signing in twice on one device replaces that token rather than stacking', function () {
    $vendor = Vendor::factory()->create(['status' => 'approved']);
    $user = User::factory()->create([
        'email' => 'seller@store.test',
        'password' => Hash::make('a-good-password'),
        'role' => 'vendor',
        'vendor_id' => $vendor->id,
    ]);

    foreach (range(1, 3) as $ignored) {
        $this->postJson(route('api.seller.login'), [
            'email' => 'seller@store.test',
            'password' => 'a-good-password',
            'device_name' => 'Pixel 9',
        ])->assertOk();
    }

    expect($user->tokens()->count())->toBe(1);

    $this->postJson(route('api.seller.login'), [
        'email' => 'seller@store.test',
        'password' => 'a-good-password',
        'device_name' => 'iPad',
    ])->assertOk();

    expect($user->tokens()->count())->toBe(2);
});

test('logout drops only this device, logout-all drops every one', function () {
    [$seller] = actingAsSeller();
    $seller->createToken('other device');

    // The signed-in device plus the one just added.
    expect($seller->tokens()->count())->toBe(2);

    $this->postJson(route('api.seller.logout'))->assertOk();

    expect($seller->fresh()->tokens()->count())->toBe(1);

    $this->app['auth']->forgetGuards();
    [$other] = actingAsSeller();
    $other->createToken('another');

    $this->postJson(route('api.seller.logout-all'))->assertOk();

    expect($other->fresh()->tokens()->count())->toBe(0);
});

test('changing the password signs other devices out but keeps this one', function () {
    [$seller] = actingAsSeller();
    $seller->forceFill(['password' => Hash::make('a-good-password')])->save();
    $seller->createToken('old phone');

    $this->putJson(route('api.seller.me.password'), [
        'current_password' => 'a-good-password',
        'password' => 'a-better-password',
        'password_confirmation' => 'a-better-password',
    ])->assertOk();

    expect(Hash::check('a-better-password', $seller->fresh()->password))->toBeTrue()
        // The old phone is gone; the device that made the change is still in.
        ->and($seller->fresh()->tokens()->count())->toBe(1)
        ->and($seller->fresh()->tokens()->first()->name)->toBe('test device');
});

test('the wrong current password is refused', function () {
    [$seller] = actingAsSeller();
    $seller->forceFill(['password' => Hash::make('a-good-password')])->save();

    $this->putJson(route('api.seller.me.password'), [
        'current_password' => 'not-it',
        'password' => 'a-better-password',
        'password_confirmation' => 'a-better-password',
    ])->assertStatus(422)->assertJsonValidationErrors('current_password');
});

test('forgot password never reveals whether the address exists', function () {
    $this->postJson(route('api.seller.forgot-password'), ['email' => 'nobody@nowhere.test'])
        ->assertOk()
        ->assertJsonPath('message', 'If that address belongs to a seller, a reset link is on its way.');
});

test('guests are turned away from every protected endpoint', function () {
    foreach ([
        route('api.seller.me'),
        route('api.seller.products.index'),
        route('api.seller.orders.index'),
        route('api.seller.payouts.index'),
        route('api.seller.dashboard'),
    ] as $url) {
        $this->getJson($url)->assertUnauthorized();
    }
});
