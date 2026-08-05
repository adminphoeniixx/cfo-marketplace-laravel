<?php

use App\Models\Order;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    // Feature tests assert on responses, not on compiled assets, so they must not
    // depend on a fresh `npm run build` being present.
    ->beforeEach(fn () => $this->withoutVite())
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Create an admin user that passes the `auth` + `verified` middleware.
 *
 * @param  array<string, mixed>  $attributes
 */
function adminUser(array $attributes = []): User
{
    return User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
        ...$attributes,
    ]);
}

/**
 * Act as an admin for the current test and return the user.
 *
 * @param  array<string, mixed>  $attributes
 */
function actingAsAdmin(array $attributes = []): User
{
    $user = adminUser($attributes);

    test()->actingAs($user);

    return $user;
}

/**
 * Create an approved store with a seller login, and act as that seller with a
 * Sanctum token. Returns [User $seller, Vendor $store].
 *
 * @param  array<string, mixed>  $vendorAttributes
 * @return array{0: User, 1: Vendor}
 */
function actingAsSeller(array $vendorAttributes = []): array
{
    $vendor = Vendor::factory()->create([
        'status' => 'approved',
        ...$vendorAttributes,
    ]);

    $seller = User::factory()->create([
        'role' => 'vendor',
        'vendor_id' => $vendor->id,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    // A real bearer token rather than `actingAs`, so the tests exercise the
    // same path the app does — including `currentAccessToken()`.
    test()->withHeader(
        'Authorization',
        'Bearer '.$seller->createToken('test device')->plainTextToken,
    );

    return [$seller, $vendor];
}

/**
 * An order carrying one line for the given store, so scoping can be asserted.
 *
 * @param  array<string, mixed>  $itemAttributes
 */
function orderForStore(Vendor $vendor, array $itemAttributes = []): Order
{
    $order = Order::factory()->create();

    $order->items()->create([
        'vendor_id' => $vendor->id,
        'name' => 'Sample item',
        'sku' => 'SKU-'.$vendor->id.'-'.fake()->unique()->numberBetween(1, 99999),
        'unit_price' => 1000,
        'quantity' => 2,
        'tax_amount' => 180,
        'total' => 2000,
        'commission_rate' => 10,
        'commission_amount' => 200,
        'vendor_earning' => 1800,
        ...$itemAttributes,
    ]);

    return $order->load('items');
}
