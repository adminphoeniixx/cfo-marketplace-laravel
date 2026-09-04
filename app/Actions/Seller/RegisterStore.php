<?php

namespace App\Actions\Seller;

use App\Models\User;
use App\Models\Vendor;
use App\Notifications\VendorRegistered;
use App\Services\Notifier;
use Illuminate\Support\Facades\DB;

/**
 * Somebody asking to sell here.
 *
 * Two doors lead in — the seller app's `POST /api/seller/register` and the
 * public `/sell` page — and both have to create exactly the same thing: a
 * store waiting for approval, and one login attached to it. Keeping that in
 * one class is what stops the web form quietly granting something the API
 * does not, which is the sort of difference nobody notices until a store is
 * trading without ever having been approved.
 */
class RegisterStore
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{0: User, 1: Vendor}
     */
    public function handle(array $data): array
    {
        [$user, $vendor] = DB::transaction(function () use ($data) {
            $vendor = Vendor::create([
                'name' => $data['store_name'],
                'store_email' => $data['store_email'] ?? $data['email'],
                'phone' => $data['phone'] ?? null,
                'gst_number' => $data['gst_number'] ?? null,
                'city' => $data['city'] ?? null,
                'state' => $data['state'] ?? null,
                'contact_name' => $data['name'],
                // Never anything else from here. Approval is a decision the
                // marketplace makes, and there is no path through this class
                // that skips it.
                'status' => 'pending',
            ]);

            $user = new User;
            $user->forceFill([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'phone' => $data['phone'] ?? null,
                'role' => 'vendor',
                'vendor_id' => $vendor->id,
                'is_active' => true,
                // The seller proved the address by receiving nothing — there is
                // no storefront to verify against, and admin approval is the
                // real gate here.
                'email_verified_at' => now(),
            ])->save();

            return [$user, $vendor];
        });

        Notifier::send(new VendorRegistered($vendor));

        return [$user, $vendor];
    }
}
