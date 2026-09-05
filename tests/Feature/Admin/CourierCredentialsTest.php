<?php

use App\Models\DeliveryPartner;
use App\Services\Couriers\Couriers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
| Credentials on the courier row, so connecting a second one is a form rather
| than a deploy.
*/

beforeEach(function () {
    actingAsAdmin();
});

test('a token is stored encrypted and never handed back to the screen', function () {
    $this->post(route('admin.delivery-partners.store'), [
        'name' => 'Shiprocket',
        'driver' => 'shiprocket',
        'credentials' => [
            'email' => 'ops@example.test',
            'password' => 'a-good-password',
        ],
    ])->assertRedirect();

    $partner = DeliveryPartner::where('name', 'Shiprocket')->firstOrFail();

    expect($partner->credentials['password'])->toBe('a-good-password')
        // What sits in Postgres is ciphertext: a database dump gives up
        // nothing.
        ->and(DB::table('delivery_partners')->where('id', $partner->id)->value('credentials'))
        ->not->toContain('a-good-password');

    // And the screen is told which fields are set, never what they are.
    $this->get(route('admin.settings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('deliveryPartners', fn ($partners) => collect($partners)
                ->firstWhere('name', 'Shiprocket')['credentials_set'] === ['email', 'password']))
        ->assertDontSee('a-good-password');
});

test('a blank secret keeps the one already stored', function () {
    $partner = DeliveryPartner::create([
        'name' => 'Shiprocket', 'code' => 'shiprocket', 'driver' => 'shiprocket',
        'credentials' => ['email' => 'ops@example.test', 'password' => 'a-good-password'],
    ]);

    // Correcting the pickup name, leaving the password field empty because it
    // was shown as dots.
    $this->put(route('admin.delivery-partners.update', $partner->id), [
        'name' => 'Shiprocket',
        'driver' => 'shiprocket',
        'credentials' => ['email' => 'ops@example.test', 'password' => '', 'pickup_location' => 'Noida'],
    ])->assertRedirect();

    expect($partner->fresh()->credentials)->toMatchArray([
        'email' => 'ops@example.test',
        'password' => 'a-good-password',
        'pickup_location' => 'Noida',
    ]);
});

test('testing a connection ships nothing and records what happened', function () {
    Http::fake([
        'apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'sr-token']),
    ]);

    $partner = DeliveryPartner::create([
        'name' => 'Shiprocket', 'code' => 'shiprocket', 'driver' => 'shiprocket',
        'credentials' => ['email' => 'ops@example.test', 'password' => 'a-good-password'],
    ]);

    $this->post(route('admin.delivery-partners.test', $partner->id))->assertRedirect();

    expect($partner->fresh()->connected_at)->not->toBeNull()
        ->and($partner->fresh()->connection_error)->toBeNull();

    // Only the sign-in. Nothing was booked.
    Http::assertSentCount(1);
});

test('a courier that refuses the credentials says so on the row', function () {
    Http::fake([
        'apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['message' => 'nope'], 401),
    ]);

    $partner = DeliveryPartner::create([
        'name' => 'Shiprocket', 'code' => 'shiprocket', 'driver' => 'shiprocket',
        'credentials' => ['email' => 'ops@example.test', 'password' => 'wrong'],
        'connected_at' => now(),
    ]);

    $this->post(route('admin.delivery-partners.test', $partner->id))->assertRedirect();

    expect($partner->fresh()->connected_at)->toBeNull()
        ->and($partner->fresh()->connection_error)->toContain('refused');
});

test('changing credentials makes a connection unproven again', function () {
    $partner = DeliveryPartner::create([
        'name' => 'Shiprocket', 'code' => 'shiprocket', 'driver' => 'shiprocket',
        'credentials' => ['email' => 'ops@example.test', 'password' => 'old'],
        'connected_at' => now(),
    ]);

    $this->put(route('admin.delivery-partners.update', $partner->id), [
        'name' => 'Shiprocket',
        'driver' => 'shiprocket',
        'credentials' => ['password' => 'new'],
    ])->assertRedirect();

    // A key nobody has tested is exactly how a courier that accepts a booking
    // and delivers nothing looks.
    expect($partner->fresh()->connected_at)->toBeNull();
});

test('a partner with no driver stays a label and a link', function () {
    // DTDC ships with the schema; it is a link and nothing more.
    $partner = DeliveryPartner::where('code', 'dtdc')->firstOrFail();

    expect(Couriers::for($partner))->toBeNull();

    $this->post(route('admin.delivery-partners.test', $partner->id))->assertRedirect();

    expect($partner->fresh()->connection_error)->toContain('No courier selected');
});
