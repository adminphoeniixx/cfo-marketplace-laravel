<?php

use App\Models\Customer;
use Database\Seeders\DemoCustomerSeeder;

/**
 * The account handed to an app store reviewer.
 *
 * Credentials nobody can sign in with are worse than none — the review comes
 * back rejected a week later — so the test that matters is the one that takes
 * what the seeder prints and posts it at the app's own login endpoint.
 */
beforeEach(function () {
    putenv('DEMO_CUSTOMER_EMAIL=reviewer@marketplace.test');
    putenv('DEMO_CUSTOMER_PASSWORD=review-me-please');
});

afterEach(function () {
    putenv('DEMO_CUSTOMER_EMAIL');
    putenv('DEMO_CUSTOMER_PASSWORD');
});

test('the seeded shopper can sign in with the printed credentials', function () {
    $this->seed(DemoCustomerSeeder::class);

    $this->postJson(route('api.customer.auth.login'), [
        'email' => 'reviewer@marketplace.test',
        'password' => 'review-me-please',
        'device_name' => 'Review device',
    ])
        ->assertOk()
        ->assertJsonPath('customer.email', 'reviewer@marketplace.test')
        ->assertJsonStructure(['token']);
});

test('running it twice leaves one account and resets the password', function () {
    $this->seed(DemoCustomerSeeder::class);

    Customer::where('email', 'reviewer@marketplace.test')
        ->firstOrFail()
        ->forceFill(['password' => 'something-else', 'status' => 'blocked'])
        ->save();

    putenv('DEMO_CUSTOMER_PASSWORD=second-time-round');
    $this->seed(DemoCustomerSeeder::class);

    expect(Customer::where('email', 'reviewer@marketplace.test')->count())->toBe(1);

    $this->postJson(route('api.customer.auth.login'), [
        'email' => 'reviewer@marketplace.test',
        'password' => 'second-time-round',
        'device_name' => 'Review device',
    ])->assertOk();
});

test('the demo shopper is marked as one', function () {
    $this->seed(DemoCustomerSeeder::class);

    $customer = Customer::where('email', 'reviewer@marketplace.test')->firstOrFail();

    expect($customer->tags)->toContain('demo')
        ->and($customer->status)->toBe('active')
        ->and($customer->orders()->count())->toBe(0);
});
