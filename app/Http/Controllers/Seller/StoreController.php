<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The seller's own shopfront and payout details — the web twin of the API's
 * `ProfileController::store()`/`updateStore()`, validating the same fields.
 *
 * Commission rate, status and rating are the marketplace's to set. They are
 * shown here read-only and are not in the validated set, so a hand-rolled
 * payload cannot promote a store or cut its own commission.
 */
class StoreController extends Controller
{
    public function edit(Request $request): Response
    {
        $vendor = $request->user()->vendor;

        return Inertia::render('seller/settings/Store', [
            'store' => $vendor->only([
                'id', 'name', 'slug', 'store_email', 'phone', 'description', 'logo_path',
                'contact_name', 'address_line1', 'address_line2', 'city', 'state',
                'postcode', 'country', 'gst_number', 'payout_method',
                'bank_account_name', 'bank_account_number', 'bank_ifsc',
            ]),
            // Set by the marketplace, shown so the seller knows where they stand.
            'marketplace' => [
                'status' => $vendor->status,
                'commission_type' => $vendor->commission_type,
                'commission_rate' => $vendor->commission_rate,
                'rating' => $vendor->rating,
                'approved_at' => $vendor->approved_at?->toIso8601String(),
            ],
            'payoutMethods' => ['bank', 'upi', 'wallet'],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $vendor = $request->user()->vendor;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'store_email' => ['nullable', 'email', 'max:180'],
            'phone' => ['nullable', 'string', 'max:25'],
            'description' => ['nullable', 'string', 'max:5000'],
            'logo_path' => ['nullable', 'string', 'max:500'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'address_line1' => ['nullable', 'string', 'max:180'],
            'address_line2' => ['nullable', 'string', 'max:180'],
            'city' => ['nullable', 'string', 'max:80'],
            'state' => ['nullable', 'string', 'max:80'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:2'],
            'gst_number' => ['nullable', 'string', 'max:20'],
            'payout_method' => ['nullable', Rule::in(['bank', 'upi', 'wallet'])],
            'bank_account_name' => ['nullable', 'string', 'max:120'],
            'bank_account_number' => ['nullable', 'string', 'max:40'],
            'bank_ifsc' => ['nullable', 'string', 'max:20'],
        ]);

        $vendor->update($data);

        return back()->with('success', 'Store details saved.');
    }
}
