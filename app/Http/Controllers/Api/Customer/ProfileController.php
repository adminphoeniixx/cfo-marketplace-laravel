<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\ProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    use ScopesToCustomer;

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => new ProfileResource($this->customer($request))]);
    }

    public function update(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        $data = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'email' => ['sometimes', 'email', 'max:180',
                Rule::unique('customers', 'email')->ignore($customer->id)->whereNull('deleted_at')],
            'phone' => ['nullable', 'string', 'max:25'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other', 'prefer_not_to_say'])],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'accepts_marketing' => ['boolean'],
        ]);

        // Changing the address un-verifies it: the new one has not been proved.
        if (isset($data['email']) && $data['email'] !== $customer->email) {
            $data['email_verified'] = false;
        }

        if (isset($data['phone']) && $data['phone'] !== $customer->phone) {
            $data['phone_verified_at'] = null;
        }

        $customer->forceFill($data)->save();

        return response()->json(['data' => new ProfileResource($customer->fresh())]);
    }

    /**
     * Set or change the password.
     *
     * A shopper who has only ever signed in with a code has none, so the
     * current one is required only when there is one to prove.
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        $data = $request->validate([
            'current_password' => [$customer->password ? 'required' : 'nullable', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ]);

        if ($customer->password && ! Hash::check($data['current_password'], $customer->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'That is not your current password.',
            ]);
        }

        $customer->forceFill(['password' => $data['password']])->save();

        // Every other device is signed out — a password change is how someone
        // takes an account back.
        $current = $request->user()->currentAccessToken()->id;
        $customer->tokens()->whereKeyNot($current)->delete();

        return response()->json(['changed' => true]);
    }
}
