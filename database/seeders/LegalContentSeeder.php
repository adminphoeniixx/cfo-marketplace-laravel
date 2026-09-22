<?php

namespace Database\Seeders;

use App\Models\LegalPage;
use App\Models\Setting;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Env;

/**
 * The privacy policy, written once so it is not written in a hurry.
 *
 * Both app stores refuse a listing without a privacy policy on a public URL,
 * and this marketplace had the row for one since the app content tables — with
 * nothing in it. This fills that row, from the store's own settings, and never
 * touches a body somebody has already written: an admin who edits the policy
 * in the panel outranks this file, always.
 *
 * Two facts cannot come from settings and must not be invented — the
 * registered company name and the name of the grievance officer. Supply them
 * with LEGAL_ENTITY_NAME and GRIEVANCE_OFFICER_NAME, or leave them and the
 * text carries a visible [placeholder] that this seeder then reads back out.
 *
 * Safe against a live database: one row, one slug, no demo data.
 */
class LegalContentSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $page = LegalPage::where('slug', 'privacy')->first();

        if (! $page) {
            $this->command->error('No "privacy" page exists — run the migrations first.');

            return;
        }

        $overwrite = (bool) Env::get('LEGAL_SEED_OVERWRITE', false);

        if ($page->body && ! $overwrite) {
            $this->command->warn('The privacy policy already has a body — left untouched.');
            $this->command->line('Pass LEGAL_SEED_OVERWRITE=1 to replace it with this draft.');

            return;
        }

        $body = $this->privacyBody();

        $page->update(['body' => $body, 'is_published' => true]);

        $this->command->info('Privacy policy written and published: /legal/privacy');

        preg_match_all('/\[[^\]]+\]/', $body, $found);

        if ($found[0] !== []) {
            $this->command->newLine();
            $this->command->warn('Still a draft — these placeholders are on a public page:');

            foreach (array_unique($found[0]) as $placeholder) {
                $this->command->line("- {$placeholder}");
            }

            $this->command->line('Fill them in the admin panel under Content → Legal pages.');
        }
    }

    /**
     * The policy itself, in the Markdown the panel's textarea takes.
     */
    private function privacyBody(): string
    {
        $store = (string) Setting::cached('store_name', config('app.name'));
        $entity = (string) (Env::get('LEGAL_ENTITY_NAME') ?: '[Registered company name]');
        $officer = (string) (Env::get('GRIEVANCE_OFFICER_NAME') ?: '[Grievance officer name]');
        $email = (string) (Setting::cached('store_email') ?: '[support email address]');
        $phone = (string) (Setting::cached('store_phone') ?: '[support phone number]');
        $address = (string) (Setting::cached('address') ?: '[registered address]');
        $date = now()->format('j F Y');

        return <<<MD
        {$store} is a marketplace: sellers list their own goods here and we run the
        checkout, the payments and the paperwork around them. This policy explains what
        we collect when you shop, why we hold it, who else sees it, and what you can ask
        us to do with it.

        It applies to the {$store} shopping app, this website and the seller panel. It is
        published by **{$entity}**, {$address}.

        ## What we collect

        **What you tell us.** Your name, mobile number and email address when you sign
        in or create an account; the delivery addresses you save, including the name and
        phone number of whoever is receiving the order; your date of birth and gender if
        you choose to add them; and anything you write to us — support messages, product
        reviews, cancellation or return reasons.

        **What your orders produce.** What you bought, from which seller, at what price,
        where it went, what you paid with, and the record of any cancellation, return,
        refund or store-credit adjustment that followed.

        **What your device tells us.** Your device's push notification token if you allow
        notifications, the app version and device model, and the IP address the request
        arrived from. We check the pincode you enter against our delivery partner to
        answer "do you deliver here?" — that check happens before there is an order or,
        often, an account.

        **What we never hold.** Your full card number, CVV or UPI PIN never reach our
        servers. Payments go to our payment gateway, and a card you choose to save is
        stored by them as a token; we keep only the last four digits, the network and the
        expiry so you can recognise it.

        ## Why we hold it

        - To let you sign in. A mobile number gets a one-time code by SMS; an email
          address and password are the other door.
        - To place, fulfil, invoice and deliver your order, and to handle the
          cancellations, returns and refunds that come after it.
        - To tell you what happened to an order — by push notification, SMS or email.
        - To answer your support tickets and act on your complaints.
        - To keep the marketplace honest: detecting fraud, abuse of store credit, and
          reviews written by somebody who never bought the thing.
        - To meet tax, accounting and other legal obligations. Invoices and payment
          records have their own retention rules, and those outlast your account.

        Marketing messages about offers are sent only if you have asked for them, and
        every one of them can be switched off in the app under notification settings.
        Order and delivery updates are not marketing and continue while you have an open
        order.

        ## Who else sees it

        - **The seller.** An order reaches the seller who has to pack it, with your name,
          delivery address and phone number. A basket split across two sellers gives each
          of them only their own part of it.
        - **Delivery partners**, who need the same details to reach your door.
        - **Our payment gateway**, which handles the payment and any refund.
        - **SMS, email and push providers**, which carry the code or the notification and
          nothing more.
        - **Anyone who reads a review.** A review you publish shows your first name next
          to it. Your phone number, email and address are never shown to other shoppers.
        - **Authorities and advisers**, where the law requires it or where we must defend
          a legal claim.

        We do not sell your personal data, and we do not hand it to anybody for their own
        advertising.

        ## How long we keep it

        Your account and its addresses stay while the account exists. Orders, invoices
        and payment records are kept for as long as tax and company law require, which is
        longer than the account itself. One-time codes expire in minutes. Support tickets
        are kept while they are useful for answering you again.

        ## Your choices

        - **See and correct.** Your profile and addresses are editable in the app, and
          you can ask us for a copy of what else we hold.
        - **Delete your account.** The app does this under Account → Delete account. We
          sign out every device, release your email and phone so they can be used again,
          and remove your profile. Orders already placed are kept, unlinked from you
          where we are allowed to unlink them, because an invoice is a legal record.
        - **Turn off notifications.** Push, SMS and email preferences are all in the app,
          and revoking notification permission on the device works too.
        - **Withdraw consent.** Where we are relying on your consent, you can take it
          back; that does not undo what was done while it stood.

        ## Keeping it safe

        Traffic runs over HTTPS. Passwords are stored hashed, never in plain text. Access
        to the admin panel is restricted to staff who need it, and every change to an
        order is logged against the person who made it. No system is perfectly secure,
        and we will tell you and the authorities if a breach affects you.

        ## Children

        The app is not meant for anyone under 18, and we do not knowingly create accounts
        for children. If you believe a child has an account here, write to us and we will
        remove it.

        ## Changes

        When this policy changes we update the date at the top of the page and, where the
        change matters to you, tell you in the app. The current version always lives at
        this address.

        ## Grievance officer

        As required by the Information Technology (Intermediary Guidelines and Digital
        Media Ethics Code) Rules, 2021 and the Digital Personal Data Protection Act,
        2023, complaints about your data can be sent to:

        - **{$officer}**, Grievance Officer
        - {$entity}, {$address}
        - Email: {$email}
        - Phone: {$phone}

        We acknowledge a complaint within 24 hours and resolve it within 15 days.

        _Last updated {$date}._
        MD;
    }
}
