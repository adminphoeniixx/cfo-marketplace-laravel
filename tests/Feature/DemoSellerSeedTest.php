<?php

use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\DemoSellerSeeder;

/**
 * The seller login handed to an app store reviewer.
 *
 * Same standard as the shopper account: the test that matters takes what the
 * seeder prints and posts it at the app's own login endpoint. A seller account
 * has one more way to be useless, though — a store still pending approval
 * opens the app on a waiting screen — so that is checked too.
 */
beforeEach(function () {
    putenv('DEMO_SELLER_EMAIL=reviewer@marketplace.test');
    putenv('DEMO_SELLER_PASSWORD=review-me-please');
    putenv('DEMO_SELLER_STORE=Reviewer Store');
});

afterEach(function () {
    putenv('DEMO_SELLER_EMAIL');
    putenv('DEMO_SELLER_PASSWORD');
    putenv('DEMO_SELLER_STORE');
});

test('the seeded seller can sign in with the printed credentials', function () {
    $this->seed(DemoSellerSeeder::class);

    $this->postJson(route('api.seller.login'), [
        'email' => 'reviewer@marketplace.test',
        'password' => 'review-me-please',
        'device_name' => 'Review device',
    ])
        ->assertOk()
        ->assertJsonStructure(['token', 'seller']);
});

test('the store is approved, so the app opens on the panel and not on a waiting screen', function () {
    $this->seed(DemoSellerSeeder::class);

    $user = User::where('email', 'reviewer@marketplace.test')->firstOrFail();

    expect($user->role)->toBe('vendor')
        ->and($user->is_active)->toBeTrue()
        ->and($user->vendor)->not->toBeNull()
        ->and($user->vendor->status)->toBe('approved')
        // The slug comes from a model event, which a seeder can switch off by
        // accident. A store without one is unreachable.
        ->and($user->vendor->slug)->not->toBeEmpty();
});

test('running it twice leaves one store and resets the password', function () {
    $this->seed(DemoSellerSeeder::class);

    $user = User::where('email', 'reviewer@marketplace.test')->firstOrFail();
    $user->forceFill(['password' => 'something-else', 'is_active' => false])->save();
    $user->vendor->forceFill(['status' => 'suspended'])->save();

    putenv('DEMO_SELLER_PASSWORD=second-time-round');
    $this->seed(DemoSellerSeeder::class);

    expect(User::where('email', 'reviewer@marketplace.test')->count())->toBe(1)
        ->and(Vendor::where('name', 'Reviewer Store')->count())->toBe(1);

    // Suspended and deactivated is how a demo account dies quietly between
    // one review and the next; the second run has to undo both.
    $this->postJson(route('api.seller.login'), [
        'email' => 'reviewer@marketplace.test',
        'password' => 'second-time-round',
        'device_name' => 'Review device',
    ])->assertOk();
});

test('it brings no catalogue and no orders with it', function () {
    $this->seed(DemoSellerSeeder::class);

    $vendor = User::where('email', 'reviewer@marketplace.test')->firstOrFail()->vendor;

    expect($vendor->products()->count())->toBe(0)
        ->and($vendor->description)->toContain('Not a real seller');
});
