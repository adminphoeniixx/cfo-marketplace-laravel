<?php

use App\Models\LegalPage;
use App\Models\Setting;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The account deletion page.
 *
 * Play Console asks for a URL that says how to close an account and what
 * happens to the data, and checks that somebody who has uninstalled the app
 * can still reach it. So the tests that matter are about who can open it and
 * whether it tells the truth about `DELETE /api/customer/me`.
 */
test('anybody can read it without signing in', function () {
    Setting::put('store_name', 'Example Bazaar');
    Setting::put('store_email', 'help@example.test');

    $this->get('/account/delete')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('account/Delete')
            ->where('store.name', 'Example Bazaar')
            ->where('store.email', 'help@example.test')
            ->has('steps', 3)
            ->has('immediate')
            ->has('kept')
            ->has('before')
        );
});

test('/delete-account is the short address for it', function () {
    $this->get('/delete-account')->assertRedirect('/account/delete');
});

test('it tells a shopper what stops a closure', function () {
    // The API refuses while an order is still on its way. Somebody who hits
    // that refusal should find it explained here rather than nowhere.
    $this->get('/account/delete')
        ->assertInertia(fn (Assert $page) => $page
            ->where('before.0', fn (string $line) => str_contains($line, 'order still on its way'))
        );
});

test('it offers a way in for somebody who has deleted the app', function () {
    Setting::put('store_email', 'help@example.test');

    $this->get('/account/delete')
        ->assertInertia(fn (Assert $page) => $page
            ->where('request.subject', 'Account deletion request')
            ->where('request.body', fn (string $body) => str_contains($body, 'mobile number'))
        );
});

test('with no support address it does not invent one', function () {
    Setting::put('store_email', '');

    $this->get('/account/delete')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('store.email', null));
});

test('the footer links only to policies that are really there', function () {
    LegalPage::where('slug', 'returns')->update([
        'body' => 'Send it back within seven days.',
        'is_published' => true,
    ]);
    LegalPage::where('slug', 'terms')->update(['body' => null, 'is_published' => true]);

    $this->get('/account/delete')
        ->assertInertia(fn (Assert $page) => $page
            ->has('legal', 1)
            ->where('legal.0.slug', 'returns')
        );
});
