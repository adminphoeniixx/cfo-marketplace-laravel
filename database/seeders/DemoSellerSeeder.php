<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One seller login somebody outside the company can sign in with.
 *
 * The same problem as the shopper account, one door further in: a reviewer
 * handed a build of the seller app has no store, no approval and no way to get
 * either, and a seller app that opens on "waiting for approval" shows nothing
 * anybody can review.
 *
 * So this makes the pair the app expects — an approved store and a login
 * attached to it — rather than going through `RegisterStore`, which creates a
 * store that is *pending* on purpose and tells the staff about it. Approval is
 * a decision the marketplace makes; this is not that decision, it is a demo
 * account, and pretending otherwise would put a fake application in somebody's
 * queue.
 *
 * Safe against a live database: one vendor row, one user row, no products, no
 * orders, no payouts.
 *
 * Supply DEMO_SELLER_EMAIL / DEMO_SELLER_PASSWORD / DEMO_SELLER_STORE to
 * choose the details; without a password one is generated and printed once.
 * Running it again with DEMO_SELLER_PASSWORD set resets the password, which is
 * how you recover the account rather than making a second one.
 */
class DemoSellerSeeder extends Seeder
{
    /*
    | No `WithoutModelEvents` here, unlike its shopper counterpart: a vendor's
    | slug is filled by a `creating` hook on the model, and a store with no
    | slug is a store no URL can reach.
    */
    public function run(): void
    {
        $email = (string) Env::get('DEMO_SELLER_EMAIL', 'demo.seller@marketplace.test');
        $store = (string) Env::get('DEMO_SELLER_STORE', 'Demo Store');
        $supplied = (string) Env::get('DEMO_SELLER_PASSWORD', '');
        $password = $supplied ?: Str::password(16, symbols: false);

        $user = User::where('email', $email)->first();

        if ($user) {
            // A demo account nobody can get into is not a demo account, so a
            // supplied password always wins over whatever is on the row.
            $user->forceFill([
                'role' => 'vendor',
                'is_active' => true,
                ...($supplied ? ['password' => $supplied] : []),
            ])->save();

            // The store may have been suspended, or the login may predate the
            // store it should be attached to.
            $vendor = $user->vendor ?? $this->store($store, $email, $user->name);
            $vendor->forceFill([
                'status' => 'approved',
                'approved_at' => $vendor->approved_at ?? now(),
            ])->save();

            if ($user->vendor_id !== $vendor->id) {
                $user->forceFill(['vendor_id' => $vendor->id])->save();
            }

            $this->command->warn("Demo seller [{$email}] already existed.");

            if (! $supplied) {
                $this->command->line('Its password was left alone — pass DEMO_SELLER_PASSWORD to reset it.');

                $this->directions($email, null, $vendor);

                return;
            }
        } else {
            [$user, $vendor] = DB::transaction(function () use ($store, $email, $password) {
                $vendor = $this->store($store, $email, 'Demo Seller');

                $user = new User;
                $user->forceFill([
                    'name' => 'Demo Seller',
                    'email' => $email,
                    'password' => $password,
                    'phone' => Env::get('DEMO_SELLER_PHONE') ?: null,
                    'role' => 'vendor',
                    'vendor_id' => $vendor->id,
                    'is_active' => true,
                    // Nothing to verify against: there is no storefront behind
                    // this and no approval left to wait for.
                    'email_verified_at' => now(),
                ])->save();

                return [$user, $vendor];
            });
        }

        $this->directions($email, $password, $vendor);
    }

    /**
     * The store itself, approved, so the panel opens on something to look at.
     */
    private function store(string $name, string $email, string $contact): Vendor
    {
        return Vendor::create([
            'name' => $name,
            'store_email' => $email,
            'contact_name' => $contact,
            'status' => 'approved',
            'approved_at' => now(),
            // So the next person through the sellers list knows why a store
            // with no products is sitting there approved.
            'description' => 'Review account for the app stores. Not a real seller — leave it be.',
        ]);
    }

    private function directions(string $email, ?string $password, Vendor $vendor): void
    {
        $this->command->newLine();
        $this->command->info('Demo seller ready. Hand these to the app store reviewer:');
        $this->command->line("Email: {$email}");

        if ($password !== null) {
            $this->command->line("Password: {$password}");
        }

        $this->command->line("Store: {$vendor->name} (approved)");

        $this->command->newLine();
        $this->command->line('It signs in at POST /api/seller/login and at /seller in a browser.');
        $this->command->line('The store has no products — add one from the panel if the review needs it.');
    }
}
