<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\CustomerPaymentMethod;
use App\Models\Setting;
use App\Models\WalletTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The payments screen: what this shopper has saved, and what they are owed.
 *
 * Both halves were static in the app — a card row that belonged to nobody and
 * a balance that was always zero.
 *
 * On saving a card: the number never arrives here. The app tokenises with the
 * gateway and posts back what is safe to show plus the token, and this
 * refuses anything that looks like a card number, because a field that
 * accepts one eventually holds one.
 */
class WalletController extends Controller
{
    use ScopesToCustomer;

    public function index(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        return response()->json([
            'data' => $customer->paymentMethods()
                ->orderByDesc('is_default')
                ->orderByDesc('id')
                ->get()
                ->map(fn (CustomerPaymentMethod $method) => self::shape($method))
                ->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        $data = $request->validate([
            'type' => ['required', Rule::in(CustomerPaymentMethod::TYPES)],
            'label' => ['nullable', 'string', 'max:120'],
            // Masked on purpose, and checked for it: twelve digits in a row is
            // a card number, whatever the field was called on the way in.
            'masked_value' => ['required', 'string', 'max:60', 'not_regex:/\d{12,}/'],
            'provider' => ['nullable', 'string', 'max:60'],
            // What the gateway gave the app in exchange for the real thing.
            'gateway_token' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'is_default' => ['boolean'],
        ]);

        $method = $customer->paymentMethods()->create([
            ...$data,
            // A row is verified only where a gateway said so, and the app
            // saying "verified" in the payload does not make it one.
            'verified' => ($data['gateway_token'] ?? null) !== null,
            'is_default' => false,
        ]);

        // The first one saved is the default, or the shopper asked for it.
        if (($data['is_default'] ?? false) || $customer->paymentMethods()->count() === 1) {
            $method->makeDefault();
        }

        return response()->json(['data' => self::shape($method->fresh())], 201);
    }

    public function makeDefault(Request $request, int $method): JsonResponse
    {
        $model = $this->customer($request)->paymentMethods()->findOrFail($method);

        $model->makeDefault();

        return response()->json(['data' => self::shape($model->fresh())]);
    }

    public function destroy(Request $request, int $method): JsonResponse
    {
        $customer = $this->customer($request);
        $model = $customer->paymentMethods()->findOrFail($method);
        $wasDefault = $model->is_default;

        $model->delete();

        // Something has to be the default, or the checkout screen opens on
        // nothing while three saved cards sit underneath it.
        if ($wasDefault) {
            $customer->paymentMethods()->orderByDesc('id')->first()?->makeDefault();
        }

        return response()->json(['deleted' => true]);
    }

    /**
     * Store credit: the balance, and every movement behind it.
     */
    public function wallet(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        $transactions = $customer->walletTransactions()
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        // The soonest lapse among credit that is still good — what the screen
        // warns about, rather than the date of the oldest row.
        $expiring = $customer->walletTransactions()
            ->live()
            ->where('amount', '>', 0)
            ->whereNotNull('expires_at')
            ->orderBy('expires_at')
            ->first();

        return response()->json([
            'data' => [
                'balance' => WalletTransaction::balanceFor($customer->id),
                'currency' => Setting::cached('currency', 'INR'),
                // Where the credit came from, where it all came from one
                // place — "Refund for #1001" rather than a bare number.
                'source' => $transactions->firstWhere('amount', '>', 0)?->description,
                'expires_at' => $expiring?->expires_at?->toIso8601String(),
                'transactions' => $transactions->map(fn (WalletTransaction $row) => [
                    'id' => $row->id,
                    'amount' => (float) $row->amount,
                    'kind' => $row->kind,
                    'description' => $row->description,
                    'order_id' => $row->order_id,
                    'expires_at' => $row->expires_at?->toIso8601String(),
                    'created_at' => $row->created_at?->toIso8601String(),
                ])->all(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function shape(CustomerPaymentMethod $method): array
    {
        return [
            'id' => $method->id,
            'type' => $method->type,
            'label' => $method->label,
            'masked_value' => $method->masked_value,
            'provider' => $method->provider,
            'verified' => (bool) $method->verified,
            'expires_at' => $method->expires_at?->toDateString(),
            'is_expired' => $method->hasExpired(),
            'is_default' => (bool) $method->is_default,
        ];
    }
}
