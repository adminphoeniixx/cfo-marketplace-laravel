<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\ProfileResource;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CustomerDeviceToken;
use App\Models\Order;
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

    /**
     * The counts the account screen puts on its rows.
     *
     * One call rather than six, because the screen draws them all at once and
     * was otherwise showing whichever numbers it had cached — or, before this,
     * constants that were true of the demo data and nothing else.
     */
    public function summary(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        // Products they have been sent that carry no rating yet — the "rate
        // this" pile the reviews screen opens on.
        $rated = $customer->reviews()->pluck('product_id')->filter()->unique();

        $pending = Order::query()
            ->where('customer_id', $customer->id)
            ->where('status', 'completed')
            ->with('items:id,order_id,product_id')
            ->get()
            ->flatMap(fn (Order $order) => $order->items->pluck('product_id'))
            ->filter()
            ->unique()
            ->diff($rated)
            ->count();

        return response()->json([
            'data' => [
                'orders_count' => (int) $customer->orders()->count(),
                'orders_spent' => (float) $customer->total_spent,
                'wishlist_count' => (int) $customer->wishlistItems()->count(),
                // Codes they could actually use today, which is the number the
                // "offers" row promises.
                'coupon_count' => (int) Coupon::usable()->count(),
                'reviews_written_count' => (int) $customer->reviews()->count(),
                'reviews_pending_count' => $pending,
                'addresses_count' => (int) $customer->addresses()->count(),
                'devices_count' => (int) $customer->tokens()->count(),
                'requests_count' => (int) ($customer->cancellations()->count() + $customer->refunds()->count()),
            ],
        ]);
    }

    /**
     * What this shopper wants to be told about.
     *
     * `email_marketing` is the old `accepts_marketing` column under the name
     * the settings screen uses; the rest were remembered in the phone, so a
     * reinstall turned them all back on.
     */
    public function notificationPreferences(Request $request): JsonResponse
    {
        return response()->json(['data' => self::preferencesOf($this->customer($request))]);
    }

    public function updateNotificationPreferences(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        $data = $request->validate([
            'push_enabled' => ['sometimes', 'boolean'],
            'order_updates' => ['sometimes', 'boolean'],
            'deals_price_drops' => ['sometimes', 'boolean'],
            'email_marketing' => ['sometimes', 'boolean'],
            'sms_order_updates' => ['sometimes', 'boolean'],
        ]);

        $customer->forceFill(array_filter([
            'push_enabled' => $data['push_enabled'] ?? null,
            'notify_order_updates' => $data['order_updates'] ?? null,
            'notify_deals' => $data['deals_price_drops'] ?? null,
            'accepts_marketing' => $data['email_marketing'] ?? null,
            'sms_order_updates' => $data['sms_order_updates'] ?? null,
        ], fn ($value) => $value !== null))->save();

        return response()->json(['data' => self::preferencesOf($customer->fresh())]);
    }

    /**
     * @return array<string, bool>
     */
    protected static function preferencesOf(Customer $customer): array
    {
        return [
            'push_enabled' => (bool) $customer->push_enabled,
            'order_updates' => (bool) $customer->notify_order_updates,
            'deals_price_drops' => (bool) $customer->notify_deals,
            'email_marketing' => (bool) $customer->accepts_marketing,
            'sms_order_updates' => (bool) $customer->sms_order_updates,
        ];
    }

    /**
     * Close the account.
     *
     * The row is soft-deleted rather than erased: orders, invoices and payouts
     * are the marketplace's own records and a deletion cannot take a seller's
     * sales history with it. What goes immediately is access — every token,
     * every registered phone — and the email and phone are released so the
     * person can sign up again.
     *
     * An order still in flight blocks it, because there is nobody left to
     * deliver to and no account to refund into.
     */
    public function destroy(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        $open = $customer->orders()
            ->whereNotIn('status', ['completed', 'cancelled', 'refunded'])
            ->count();

        if ($open > 0) {
            throw ValidationException::withMessages([
                'account' => "You have {$open} order(s) still on the way. The account can be closed once they arrive or are cancelled.",
            ]);
        }

        $suffix = '+deleted-'.$customer->id;

        $customer->forceFill([
            'status' => 'inactive',
            // Released, so the same person can sign up again tomorrow. The
            // unique indexes ignore soft-deleted rows, but the phone has no
            // such scope, so both are moved out of the way here.
            'email' => $customer->email ? $customer->email.$suffix : null,
            'phone' => $customer->phone ? $customer->phone.$suffix : null,
        ])->save();

        $customer->tokens()->delete();
        CustomerDeviceToken::where('customer_id', $customer->id)->delete();

        $customer->delete();

        return response()->json([
            'deleted' => true,
            'message' => 'Your account is closed and every device has been signed out. Orders already placed stay on the marketplace’s records.',
        ]);
    }
}
