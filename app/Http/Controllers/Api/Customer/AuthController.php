<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\ProfileResource;
use App\Models\Customer;
use App\Models\CustomerDeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Getting into the shopper app.
 *
 * Two doors, because the app offers two: a phone number and a six-digit code,
 * which is how most people will sign in, and an email and password for those
 * who set one. Both end in the same place — a Sanctum token whose tokenable is
 * a `Customer`, never a `User`.
 *
 * Signing in by phone creates the account if it is new. That is deliberate:
 * asking someone to "register" before they can order is a step the shopper app
 * does not have.
 */
class AuthController extends Controller
{
    /** How long a code is good for, and how many tries it survives. */
    protected const CODE_TTL = 600;

    protected const MAX_ATTEMPTS = 5;

    /**
     * Send a login code to a phone number.
     *
     * There is no SMS provider wired up yet, so the code is logged and — only
     * outside production — returned in the response, which is what lets the
     * app and the tests run end to end today. The moment a provider exists,
     * this method is the only one that changes.
     */
    public function requestCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'min:8', 'max:25'],
        ]);

        $phone = $this->normalisePhone($data['phone']);
        $code = (string) random_int(100000, 999999);

        Cache::put($this->codeKey($phone), ['code' => Hash::make($code), 'attempts' => 0], self::CODE_TTL);

        Log::info('Customer login code issued', ['phone' => $phone, 'code' => $code]);

        return response()->json([
            'sent' => true,
            'expires_in' => self::CODE_TTL,
            // Never in production: a code in the response would make the whole
            // exercise pointless.
            'debug_code' => app()->environment('production') ? null : $code,
        ]);
    }

    /**
     * Exchange a code for a token, creating the shopper on first sight.
     */
    public function verifyCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'min:8', 'max:25'],
            'code' => ['required', 'string', 'size:6'],
            'device_name' => ['required', 'string', 'max:120'],
            'first_name' => ['nullable', 'string', 'max:80'],
        ]);

        $phone = $this->normalisePhone($data['phone']);
        $key = $this->codeKey($phone);
        $entry = Cache::get($key);

        if (! $entry) {
            throw ValidationException::withMessages(['code' => 'That code has expired. Ask for a new one.']);
        }

        if ($entry['attempts'] >= self::MAX_ATTEMPTS) {
            Cache::forget($key);
            throw ValidationException::withMessages(['code' => 'Too many wrong codes. Ask for a new one.']);
        }

        if (! Hash::check($data['code'], $entry['code'])) {
            Cache::put($key, ['code' => $entry['code'], 'attempts' => $entry['attempts'] + 1], self::CODE_TTL);
            throw ValidationException::withMessages(['code' => 'That code is not right.']);
        }

        Cache::forget($key);

        $customer = Customer::where('phone', $phone)->first();

        if (! $customer) {
            $customer = Customer::create([
                'first_name' => $data['first_name'] ?? 'Shopper',
                'phone' => $phone,
                // Placeholder until they add a real one: the column is unique
                // and every customer record needs something in it.
                'email' => 'phone-'.$phone.'@customers.local',
                'status' => 'active',
            ]);
        }

        $customer->forceFill(['phone_verified_at' => now()])->save();

        return $this->tokenResponse($customer, $data['device_name']);
    }

    /**
     * Sign up with an email and a password, for shoppers who would rather.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:180', Rule::unique('customers', 'email')->whereNull('deleted_at')],
            'phone' => ['nullable', 'string', 'max:25'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
            'accepts_marketing' => ['boolean'],
            'device_name' => ['required', 'string', 'max:120'],
        ]);

        $customer = Customer::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'] ?? null,
            'email' => $data['email'],
            'phone' => isset($data['phone']) ? $this->normalisePhone($data['phone']) : null,
            'password' => $data['password'],
            'accepts_marketing' => $data['accepts_marketing'] ?? false,
            'status' => 'active',
        ]);

        return $this->tokenResponse($customer, $data['device_name'], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:120'],
        ]);

        $customer = Customer::where('email', $data['email'])->first();

        if (! $customer || ! $customer->password || ! Hash::check($data['password'], $customer->password)) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        if ($customer->status !== 'active') {
            throw ValidationException::withMessages(['email' => 'This account is not active.']);
        }

        return $this->tokenResponse($customer, $data['device_name']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $status = Password::broker('customers')->sendResetLink($request->only('email'));

        // Always the same answer: whether an address is registered is not
        // something an unauthenticated caller gets to find out.
        return response()->json([
            'sent' => true,
            'status' => __($status),
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ]);

        $status = Password::broker('customers')->reset($data, function (Customer $customer, string $password) {
            $customer->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();

            // A reset is also how someone recovers a stolen account, so every
            // other device is signed out.
            $customer->tokens()->delete();
        });

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return response()->json(['reset' => true]);
    }

    /**
     * Swap a live token for a fresh one, same device name.
     */
    public function refresh(Request $request): JsonResponse
    {
        $customer = $request->user();
        $name = $request->user()->currentAccessToken()->name;

        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'token' => $customer->createToken($name)->plainTextToken,
            'customer' => new ProfileResource($customer),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['signed_out' => true]);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();
        CustomerDeviceToken::where('customer_id', $request->user()->id)->delete();

        return response()->json(['signed_out' => true]);
    }

    /**
     * Every device holding a token, so the account screen can list them.
     */
    public function devices(Request $request): JsonResponse
    {
        $current = $request->user()->currentAccessToken()->id;

        return response()->json([
            'data' => $request->user()->tokens()
                ->orderByDesc('last_used_at')
                ->get()
                ->map(fn (PersonalAccessToken $token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'current' => $token->id === $current,
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'created_at' => $token->created_at?->toIso8601String(),
                ]),
        ]);
    }

    public function revokeDevice(Request $request, int $token): JsonResponse
    {
        $deleted = $request->user()->tokens()->whereKey($token)->delete();

        abort_if($deleted === 0, 404);

        return response()->json(['revoked' => true]);
    }

    protected function tokenResponse(Customer $customer, string $device, int $status = 200): JsonResponse
    {
        // A fresh sign-in on the same device replaces that device's old token
        // rather than piling up a new one on every reinstall.
        $customer->tokens()->where('name', $device)->delete();

        return response()->json([
            'token' => $customer->createToken($device)->plainTextToken,
            'customer' => new ProfileResource($customer),
        ], $status);
    }

    /** Digits only, so "+91 98765 43210" and "9876543210" are one person. */
    protected function normalisePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return mb_substr($digits, -10);
    }

    protected function codeKey(string $phone): string
    {
        return "customer-login-code:{$phone}";
    }
}
