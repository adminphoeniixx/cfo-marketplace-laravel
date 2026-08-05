<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Http\Resources\Seller\ProfileResource;
use App\Http\Resources\Seller\StoreResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function show(Request $request): ProfileResource
    {
        return new ProfileResource($request->user()->load('vendor'));
    }

    public function update(Request $request): ProfileResource
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:25'],
        ]);

        $user->forceFill($data)->save();

        return new ProfileResource($user->load('vendor'));
    }

    /**
     * Changing the password signs every other device out — a password change
     * is how someone reacts to a device going missing.
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'That is not your current password.',
            ]);
        }

        $currentId = AuthController::currentTokenId($request);

        $user->forceFill(['password' => $data['password']])->save();
        $user->tokens()
            ->when($currentId, fn ($query) => $query->whereKeyNot($currentId))
            ->delete();

        return response()->json(['message' => 'Password changed. Other devices were signed out.']);
    }

    public function store(Request $request): StoreResource
    {
        return new StoreResource($request->user()->vendor);
    }

    /**
     * The seller owns their shopfront details and payout account. Commission,
     * status and rating are the marketplace's to set and are not accepted here
     * even if they turn up in the payload.
     */
    public function updateStore(Request $request): StoreResource
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

        return new StoreResource($vendor->fresh());
    }
}
