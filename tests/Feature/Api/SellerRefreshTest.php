<?php

use App\Models\DeviceToken;
use App\Models\User;
use App\Models\Vendor;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Tokens age out on wall-clock time. Refresh is what keeps a device that is
 * used every day from being thrown back to the login screen once a month.
 */
test('a working token is traded for a fresh one', function () {
    [$me] = actingAsSeller();

    $response = $this->postJson(route('api.seller.refresh'))
        ->assertOk()
        ->assertJsonStructure(['token', 'seller' => ['id', 'email', 'sections', 'store']]);

    $fresh = $response->json('token');

    expect($fresh)->toBeString()->not->toBeEmpty();
    $this->app['auth']->forgetGuards();

    // The new one works.
    $this->withHeader('Authorization', "Bearer {$fresh}")
        ->getJson(route('api.seller.me'))
        ->assertOk()
        ->assertJsonPath('data.id', $me->id);
});

test('the old token stops working the moment it is refreshed', function () {
    [$me] = actingAsSeller();
    $old = $me->createToken('old phone')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$old}")
        ->postJson(route('api.seller.refresh'))
        ->assertOk();

    // The guard caches the resolved user for the lifetime of the application,
    // which a single test shares across requests. Production gets a fresh app
    // per request; this reproduces that.
    $this->app['auth']->forgetGuards();

    $this->withHeader('Authorization', "Bearer {$old}")
        ->getJson(route('api.seller.me'))
        ->assertUnauthorized();
});

test('refreshing leaves exactly one token for that device', function () {
    [$me] = actingAsSeller();

    expect($me->tokens()->count())->toBe(1);

    $this->postJson(route('api.seller.refresh'))->assertOk();

    expect($me->tokens()->count())->toBe(1);
});

test('the device keeps its name across a refresh', function () {
    [$me] = actingAsSeller();

    $this->postJson(route('api.seller.refresh'))->assertOk();

    expect($me->tokens()->first()->name)->toBe('test device');
});

test('an app may rename the device while refreshing', function () {
    [$me] = actingAsSeller();

    $this->postJson(route('api.seller.refresh'), ['device_name' => 'Meera iPhone 15'])->assertOk();

    expect($me->tokens()->first()->name)->toBe('Meera iPhone 15');
});

test('a refresh does not disturb the seller\'s other devices', function () {
    [$me] = actingAsSeller();
    $tablet = $me->createToken('tablet')->plainTextToken;

    $this->postJson(route('api.seller.refresh'))->assertOk();
    $this->app['auth']->forgetGuards();

    $this->withHeader('Authorization', "Bearer {$tablet}")
        ->getJson(route('api.seller.me'))
        ->assertOk();
});

test('a refresh does not disturb the push registration', function () {
    [$me] = actingAsSeller();
    DeviceToken::remember($me, 'phone-token');

    $this->postJson(route('api.seller.refresh'))->assertOk();

    expect(DeviceToken::where('user_id', $me->id)->count())->toBe(1);
});

test('an expired token cannot refresh itself', function () {
    [$me] = actingAsSeller();

    // Sanctum expires on the token's own age, so age it past the window.
    config()->set('sanctum.expiration', 60);
    PersonalAccessToken::query()->update(['created_at' => now()->subDays(2)]);
    $this->app['auth']->forgetGuards();

    $this->postJson(route('api.seller.refresh'))->assertUnauthorized();
});

test('a guest cannot refresh', function () {
    $this->postJson(route('api.seller.refresh'))->assertUnauthorized();
});

test('a deactivated seller cannot refresh', function () {
    [$me] = actingAsSeller();

    // `is_active` is guarded, so it takes a forceFill to move.
    $me->forceFill(['is_active' => false])->save();
    $this->app['auth']->forgetGuards();

    $this->postJson(route('api.seller.refresh'))->assertForbidden();
});

test('a seller still awaiting approval may refresh', function () {
    $vendor = Vendor::factory()->create(['status' => 'pending']);
    $seller = User::factory()->create([
        'role' => 'vendor',
        'vendor_id' => $vendor->id,
        'is_active' => true,
    ]);

    $this->withHeader('Authorization', 'Bearer '.$seller->createToken('phone')->plainTextToken)
        ->postJson(route('api.seller.refresh'))
        ->assertOk()
        ->assertJsonPath('seller.store.can_trade', false);
});

test('signing out after refreshing revokes the new token, not a ghost', function () {
    [$me] = actingAsSeller();

    $fresh = $this->postJson(route('api.seller.refresh'))->json('token');
    $this->app['auth']->forgetGuards();

    $this->withHeader('Authorization', "Bearer {$fresh}")
        ->postJson(route('api.seller.logout'))
        ->assertOk();

    expect($me->tokens()->count())->toBe(0);
});
