<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Http\Resources\Seller\ProfileResource;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\VendorRegistered;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /**
     * Sign up as a new seller. The store lands in `pending`: the token works
     * straight away so the app can show a "waiting for approval" screen, but
     * every trading endpoint stays shut until an admin approves it.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
            'phone' => ['nullable', 'string', 'max:25'],
            'store_name' => ['required', 'string', 'max:120'],
            'store_email' => ['nullable', 'email', 'max:180'],
            'gst_number' => ['nullable', 'string', 'max:20'],
            'device_name' => ['required', 'string', 'max:120'],
        ]);

        [$user, $vendor] = DB::transaction(function () use ($data) {
            $vendor = Vendor::create([
                'name' => $data['store_name'],
                'store_email' => $data['store_email'] ?? $data['email'],
                'phone' => $data['phone'] ?? null,
                'gst_number' => $data['gst_number'] ?? null,
                'contact_name' => $data['name'],
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

        return response()->json([
            'token' => $user->createToken($data['device_name'])->plainTextToken,
            'seller' => new ProfileResource($user->load('vendor')),
        ], 201);
    }

    /**
     * Exchange credentials for a token. One token per device, so signing out
     * of a phone does not sign out of a tablet.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:120'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        if (! $user->isVendor()) {
            throw ValidationException::withMessages([
                'email' => 'This login is not a seller account.',
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => 'This account has been deactivated.',
            ]);
        }

        // A fresh sign-in on the same device replaces that device's old token
        // rather than piling up a new one every time the app reinstalls.
        $user->tokens()->where('name', $data['device_name'])->delete();

        return response()->json([
            'token' => $user->createToken($data['device_name'])->plainTextToken,
            'seller' => new ProfileResource($user->load('vendor')),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        // Look the row up from the bearer token rather than through
        // `currentAccessToken()`: a cookie-based session has no revocable row
        // and would hand back a transient stand-in.
        PersonalAccessToken::findToken((string) $request->bearerToken())?->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    /**
     * Drop every token — the "sign out everywhere" button.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Signed out on every device.']);
    }

    /**
     * Devices currently holding a token, so a seller can see and revoke them.
     */
    public function devices(Request $request): JsonResponse
    {
        $currentId = self::currentTokenId($request);

        return response()->json([
            'data' => $request->user()->tokens()
                ->latest('last_used_at')
                ->get()
                ->map(fn ($token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'created_at' => $token->created_at?->toIso8601String(),
                    'current' => $token->id === $currentId,
                ]),
        ]);
    }

    public function revokeDevice(Request $request, int $token): JsonResponse
    {
        $deleted = $request->user()->tokens()->whereKey($token)->delete();

        return response()->json([
            'message' => $deleted ? 'Device signed out.' : 'No such device.',
        ], $deleted ? 200 : 404);
    }

    /**
     * Start a password reset. The response never says whether the address
     * exists — that would turn this into a way to enumerate sellers.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        Password::broker()->sendResetLink($data);

        return response()->json([
            'message' => 'If that address belongs to a seller, a reset link is on its way.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ]);

        $status = Password::broker()->reset($data, function (User $user, string $password) {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();

            // A reset is how someone recovers a stolen account, so every
            // existing token has to stop working.
            $user->tokens()->delete();
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => __($status),
            ]);
        }

        return response()->json(['message' => 'Password changed. Sign in again.']);
    }

    /**
     * Id of the token making this request, or null when the caller is not
     * holding a real one.
     */
    public static function currentTokenId(Request $request): ?int
    {
        return PersonalAccessToken::findToken((string) $request->bearerToken())?->id;
    }
}
