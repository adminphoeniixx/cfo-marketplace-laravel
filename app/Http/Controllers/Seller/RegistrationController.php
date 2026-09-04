<?php

namespace App\Http\Controllers\Seller;

use App\Actions\Seller\RegisterStore;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\Vendor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Sell on this marketplace", for anyone with a browser.
 *
 * The seller app has had `POST /api/seller/register` since the first release,
 * but a shopper tapping "Sell with us" had nowhere to land: the only web
 * signup was `/register`, which makes a *staff* login. This is the missing
 * door, and it goes through the same `RegisterStore` the API does — so a store
 * created here is pending approval exactly like one created from the phone.
 */
class RegistrationController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('sell/Register', [
            'store' => [
                'name' => Setting::cached('store_name', config('app.name')),
                'email' => Setting::cached('store_email') ?: null,
                'phone' => Setting::cached('store_phone') ?: null,
                'commission' => (float) Setting::cached('default_commission', 10),
            ],
            // Proof this is a working marketplace rather than a form: how many
            // stores are already trading here.
            'sellers_count' => Vendor::where('status', 'approved')->count(),
        ]);
    }

    public function store(Request $request, RegisterStore $register): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
            'phone' => ['nullable', 'string', 'max:25'],
            'store_name' => ['required', 'string', 'max:120'],
            'store_email' => ['nullable', 'email', 'max:180'],
            'gst_number' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:80'],
            'state' => ['nullable', 'string', 'max:80'],
        ]);

        [$user] = $register->handle($data);

        // Signed in straight away. The panel itself is closed until the store
        // is approved, and `/seller/pending` is what they see meanwhile —
        // better than a login screen for an account they just created.
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('seller.pending');
    }

    /**
     * What a seller sees while somebody is looking at their application.
     */
    public function pending(Request $request): Response
    {
        $vendor = $request->user()?->vendor;

        return Inertia::render('sell/Pending', [
            'store' => [
                'name' => $vendor?->name,
                'status' => $vendor?->status,
                'rejection_reason' => $vendor?->rejection_reason,
                'applied_at' => $vendor?->created_at?->toIso8601String(),
            ],
            'support' => [
                'email' => Setting::cached('store_email') ?: null,
                'phone' => Setting::cached('store_phone') ?: null,
            ],
        ]);
    }
}
