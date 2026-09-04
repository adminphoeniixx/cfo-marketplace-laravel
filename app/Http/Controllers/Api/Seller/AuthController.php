<?php

namespace App\Http\Controllers\Api\Seller;

use App\Actions\Seller\RegisterStore;
use App\Http\Controllers\Controller;
use App\Http\Resources\Seller\ProfileResource;
use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        // Shared with the public `/sell` form, so the two doors into this
        // marketplace cannot drift apart.
        [$user] = app(RegisterStore::class)->handle($data);

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

        // The app passes its Firebase token so this phone stops receiving the
        // seller's orders the moment they sign out of it.
        if ($push = $request->string('device_token')->toString()) {
            DeviceToken::where('user_id', $request->user()?->id)
                ->where('token_hash', DeviceToken::hashFor($push))
                ->delete();
        }

        return response()->json(['message' => 'Signed out.']);
    }

    /**
     * Trade a working token for a fresh one.
     *
     * Tokens expire on wall-clock age (`SANCTUM_TOKEN_MINUTES`), so without
     * this an app that is used every day still throws the seller back to the
     * login screen once a month. Calling this on resume keeps a device signed
     * in for as long as it keeps being used.
     *
     * The old token is revoked, so a stolen copy stops working the moment the
     * real device refreshes — but only after the new one exists, or a failure
     * mid-way would sign the seller out.
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = PersonalAccessToken::findToken((string) $request->bearerToken());

        // Keep the device's own name, so `/devices` does not fill up with
        // "unknown" rows as tokens roll over.
        // `??` already tolerates a null $current, so no nullsafe operator here.
        $name = $request->string('device_name')->toString()
            ?: ($current->name ?? 'seller app');

        $token = $user->createToken($name)->plainTextToken;

        $current?->delete();

        return response()->json([
            'token' => $token,
            'seller' => new ProfileResource($user->load('vendor')),
        ]);
    }

    /**
     * Drop every token — the "sign out everywhere" button.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        // Nothing is signed in any more, so nothing should be pushed to.
        DeviceToken::where('user_id', $request->user()->id)->delete();

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
            // existing token has to stop working — including the push tokens
            // of whatever phone the thief was holding.
            $user->tokens()->delete();
            DeviceToken::where('user_id', $user->id)->delete();
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
