<?php

use App\Actions\Shipping\CheckServiceability;
use App\Models\DeliveryPartner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
| "Do you deliver to my pincode?"
|
| Both clients could answer this from the day they were written and nothing
| asked them, so a shopper in a pincode no courier serves could fill a basket,
| pay, and find out days later.
|
| The rule that matters most here is the one about *not* answering: a courier
| that cannot be reached must never close a checkout. Every unreachable path
| below is asserted to fail open, and `checked` is what tells an honest yes
| from a silent one.
*/

beforeEach(function () {
    config([
        'services.delhivery.token' => 'test-token',
        'services.delhivery.base_url' => 'https://staging-express.delhivery.com',
        'services.delhivery.pickup_name' => 'CFO Noida',
    ]);

    DeliveryPartner::where('name', 'Delhivery')->update(['driver' => 'delhivery', 'is_active' => true]);

    // The answer is cached for twelve hours on purpose, which would otherwise
    // make the second test in this file read the first one's fixture.
    Cache::flush();
});

/** Delhivery's shape for a pincode it serves. */
function pincodeAnswer(string $cod = 'Y', string $prepaid = 'Y'): array
{
    return ['delivery_codes' => [['postal_code' => [
        'pin' => 110001, 'cod' => $cod, 'pre_paid' => $prepaid, 'pickup' => 'Y',
    ]]]];
}

test('a served pincode comes back as a checked yes', function () {
    Http::fake(['*/c/api/pin-codes/json/*' => Http::response(pincodeAnswer())]);

    $answer = app(CheckServiceability::class)->handle('110001');

    expect($answer['serviceable'])->toBeTrue()
        ->and($answer['cod'])->toBeTrue()
        ->and($answer['checked'])->toBeTrue()
        ->and($answer['carrier'])->toBe('Delhivery');
});

test('prepaid-only pincodes are reported as such rather than as unserved', function () {
    Http::fake(['*/c/api/pin-codes/json/*' => Http::response(pincodeAnswer(cod: 'N'))]);

    $answer = app(CheckServiceability::class)->handle('110001');

    expect($answer['serviceable'])->toBeTrue()
        ->and($answer['cod'])->toBeFalse()
        ->and($answer['checked'])->toBeTrue();
});

test('a pincode every courier refuses is a checked no', function () {
    Http::fake(['*/c/api/pin-codes/json/*' => Http::response(['delivery_codes' => []])]);

    $answer = app(CheckServiceability::class)->handle('797001');

    expect($answer['serviceable'])->toBeFalse()
        ->and($answer['cod'])->toBeFalse()
        ->and($answer['checked'])->toBeTrue();
});

test('a courier that cannot be reached leaves the checkout open', function () {
    Http::fake(fn () => throw new ConnectionException('Delhivery is having a morning.'));

    $answer = app(CheckServiceability::class)->handle('110001');

    // Everything true, and `checked` false to say why — an unanswered question
    // must read exactly like a yes downstream, and like nothing at all to a
    // shopper.
    expect($answer['serviceable'])->toBeTrue()
        ->and($answer['cod'])->toBeTrue()
        ->and($answer['checked'])->toBeFalse();
});

test('with no courier connected the question is never asked', function () {
    DeliveryPartner::query()->update(['driver' => null]);
    Http::fake();

    $answer = app(CheckServiceability::class)->handle('110001');

    expect($answer['checked'])->toBeFalse();
    Http::assertNothingSent();
});

test('the answer is cached, so a product page does not bill a courier per view', function () {
    Http::fake(['*/c/api/pin-codes/json/*' => Http::response(pincodeAnswer())]);

    app(CheckServiceability::class)->handle('110001');
    app(CheckServiceability::class)->handle('110001');
    app(CheckServiceability::class)->handle('110001');

    Http::assertSentCount(1);
});

test('the shopper endpoint answers without a token and says what it means', function () {
    Http::fake(['*/c/api/pin-codes/json/*' => Http::response(pincodeAnswer(cod: 'N'))]);

    $this->getJson(route('api.customer.serviceability', ['pincode' => '110001']))
        ->assertOk()
        ->assertJsonPath('serviceable', true)
        ->assertJsonPath('cod_available', false)
        ->assertJsonPath('checked', true)
        ->assertJsonPath('message', 'Delivered here. Cash on delivery is not available for this pincode.');
});

test('a pincode that is not six digits is refused before a courier is troubled', function () {
    Http::fake();

    $this->getJson(route('api.customer.serviceability', ['pincode' => '11']))
        ->assertStatus(422);

    Http::assertNothingSent();
});

test('an unreachable courier says nothing rather than making a promise', function () {
    Http::fake(fn () => throw new ConnectionException('down'));

    $this->getJson(route('api.customer.serviceability', ['pincode' => '110001']))
        ->assertOk()
        ->assertJsonPath('checked', false)
        ->assertJsonPath('message', null);
});
