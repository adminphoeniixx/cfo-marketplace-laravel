<?php

use App\Models\Customer;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;

/*
| What happens when the mail relay is down.
|
| Nothing in this marketplace is queued, so a send happens inside the request
| that triggered it and its failure escapes into that request. A relay having a
| bad morning must not become a 500 on a sign-up form or on the one screen
| somebody locked out of their account has left.
*/

beforeEach(function () {
    // Every send throws, as a rejected SMTP login does.
    Mail::shouldReceive('mailer')->andThrow(new TransportException('535 Authentication failed'));
});

test('a shopper can still ask for a reset link while mail is down', function () {
    Customer::factory()->create(['email' => 'priya@example.com', 'status' => 'active']);

    $this->postJson(route('api.customer.auth.forgot-password'), ['email' => 'priya@example.com'])
        ->assertOk()
        ->assertJsonPath('sent', true);
});

test('a seller can still ask for a reset link while mail is down', function () {
    User::factory()->create(['email' => 'meera@example.com', 'role' => 'vendor']);

    $this->postJson(route('api.seller.forgot-password'), ['email' => 'meera@example.com'])
        ->assertOk();
});

test('a store can still be registered while mail is down', function () {
    // The marketplace is told about a new seller; being unable to say so must
    // not stop the store existing.
    adminUser();

    $this->post(route('sell.store'), [
        'store_name' => 'Meera Textiles',
        'name' => 'Meera Iyer',
        'email' => 'meera@example.com',
        'password' => 'a-good-password',
        'password_confirmation' => 'a-good-password',
    ])->assertRedirect(route('seller.pending'));

    expect(Vendor::where('name', 'Meera Textiles')->exists())->toBeTrue();
});
