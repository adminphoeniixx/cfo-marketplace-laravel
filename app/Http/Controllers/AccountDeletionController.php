<?php

namespace App\Http\Controllers;

use App\Models\LegalPage;
use App\Models\Setting;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "How do I delete my account?", on a URL rather than inside the app.
 *
 * Play Console asks every app that lets somebody sign up for a web page that
 * says how to close the account and what happens to the data, reachable by a
 * person who has already uninstalled the app — which is exactly the person
 * most likely to be asking.
 *
 * The words here are not in the settings table on purpose. They describe what
 * `DELETE /api/customer/me` actually does, so they have to move when that
 * moves; an admin editing a textarea would drift away from the code and the
 * page would start promising deletions that never happen.
 */
class AccountDeletionController extends Controller
{
    public function show(): Response
    {
        $email = Setting::cached('store_email') ?: null;

        return Inertia::render('account/Delete', [
            'store' => [
                'name' => Setting::cached('store_name', config('app.name')),
                'email' => $email,
                'phone' => Setting::cached('store_phone') ?: null,
            ],

            // The in-app route, which is the one that needs no waiting.
            'steps' => [
                'Open the app and sign in with the number or email the account uses.',
                'Go to Account, then Delete account.',
                'Confirm. The account closes straight away — there is nothing to wait for.',
            ],

            /*
            | Everything below is `ProfileController@destroy` in words. Each
            | line is something that code does, and nothing it does not: the
            | account is closed and withdrawn from use rather than erased,
            | because the orders behind it are somebody else's records too.
            */
            'immediate' => [
                'Every device is signed out, and the sign-in tokens are destroyed.',
                'Registered devices are forgotten, so no further notifications are sent.',
                'Your email address and mobile number are released — you can use them to sign up again tomorrow.',
                'The account is closed. It cannot be signed in to, and it no longer appears to our staff as a shopper.',
            ],
            'kept' => [
                'Orders, invoices and payment records. Indian tax and company law set how long these are held, and each one is also the seller\'s record of a sale, not only yours.',
                'They stay attached to the closed account so an invoice remains attributable to the sale it was issued for. Nobody can sign in to that account to reach them.',
            ],
            'before' => [
                'An order still on its way will stop the closure: the account can be closed once every order has arrived, been cancelled or been refunded.',
                'Store credit left on the account stays with it. It cannot be moved to a new account or paid out, so spend it first.',
            ],

            // A shopper who deleted the app cannot tap Account → Delete
            // account, so there has to be a way in that does not need it.
            'request' => [
                'subject' => 'Account deletion request',
                'body' => "Please close my account.\n\nThe mobile number on the account:\nThe email address on the account (if any):\n\nI understand orders and invoices already placed are kept as the law requires.",
            ],

            // Whatever policies are actually published, for the footer.
            'legal' => LegalPage::readable()
                ->orderBy('title')
                ->get(['slug', 'title'])
                ->map(fn (LegalPage $page) => $page->only(['slug', 'title']))
                ->all(),
        ]);
    }
}
