<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Razorpay, spoken over HTTP rather than through their SDK.
 *
 * Three things happen here and nowhere else: an order is opened on the
 * gateway, a signature coming back from the app is checked, and a webhook body
 * is checked. Everything about money that follows — flipping the order, the
 * receipt the shopper sees — belongs to `Payment`, so this class never writes
 * to the database.
 *
 * Amounts cross the wire in paise. That conversion lives here, once: a rupee
 * figure escaping into the request body is a hundredfold error nobody notices
 * until a shopper is charged ₹9,345 for a ₹93.45 basket.
 */
class Razorpay
{
    private const BASE = 'https://api.razorpay.com/v1';

    /**
     * Whether real payments are switched on for this environment.
     *
     * Everything downstream keys off this: with no credentials the marketplace
     * keeps its old behaviour of treating a non-COD order as captured, which
     * is what lets the demo and the test suite run without a gateway.
     */
    public static function enabled(): bool
    {
        return self::key() !== null && self::secret() !== null;
    }

    public static function key(): ?string
    {
        return self::config('key');
    }

    /**
     * Open an order on the gateway and hand back its id.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When the gateway refuses or cannot be reached.
     */
    public static function createOrder(Order $order, float $amount): array
    {
        $response = self::request()->post(self::BASE.'/orders', [
            'amount' => self::toPaise($amount),
            'currency' => $order->currency ?: self::config('currency') ?: 'INR',
            // Razorpay caps the receipt at 40 characters, and an order number
            // is nowhere near that.
            'receipt' => mb_substr((string) $order->number, 0, 40),
            'notes' => [
                'order_number' => (string) $order->number,
                'customer_id' => (string) $order->customer_id,
            ],
        ]);

        if (! $response->successful()) {
            self::fail('create order', $response->json('error.description'), $response->status());
        }

        /** @var array<string, mixed> $body */
        $body = $response->json();

        return $body;
    }

    /**
     * One payment, as the gateway sees it. Null when it cannot be read — the
     * caller has already trusted a signature, so this is enrichment, not proof.
     *
     * @return array<string, mixed>|null
     */
    public static function fetchPayment(string $paymentId): ?array
    {
        try {
            $response = self::request()->get(self::BASE.'/payments/'.$paymentId);
        } catch (ConnectionException $e) {
            Log::warning('Razorpay payment lookup failed.', ['payment_id' => $paymentId, 'error' => $e->getMessage()]);

            return null;
        }

        /** @var array<string, mixed>|null $body */
        $body = $response->successful() ? $response->json() : null;

        return $body;
    }

    /**
     * Did this really come back from the gateway?
     *
     * The checkout widget hands the app three strings; the signature is an
     * HMAC of the other two under the secret. Anyone can post the first two,
     * so this is the only thing standing between a `verify` call and a free
     * order.
     */
    public static function verifyPaymentSignature(string $gatewayOrderId, string $paymentId, string $signature): bool
    {
        return self::matches("{$gatewayOrderId}|{$paymentId}", $signature, self::secret());
    }

    /**
     * The same question for a webhook, whose body is signed whole and under a
     * different secret.
     */
    public static function verifyWebhookSignature(string $payload, string $signature): bool
    {
        return self::matches($payload, $signature, self::config('webhook_secret'));
    }

    /**
     * Rupees to paise, the way Razorpay counts.
     *
     * Rounded before the cast: `(int) (93.45 * 100)` is 9344 on a binary float
     * and the shopper is short-changed by a paisa.
     */
    public static function toPaise(float $amount): int
    {
        return (int) round($amount * 100);
    }

    public static function toRupees(int|float|null $paise): float
    {
        return round((float) $paise / 100, 2);
    }

    private static function matches(string $payload, string $signature, ?string $secret): bool
    {
        if ($secret === null || $signature === '') {
            return false;
        }

        // `hash_equals` rather than `===`: the comparison is against a secret
        // and a timing side channel is the whole point of the function.
        return hash_equals(hash_hmac('sha256', $payload, $secret), $signature);
    }

    private static function request(): PendingRequest
    {
        if (! self::enabled()) {
            throw new RuntimeException('Razorpay is not configured.');
        }

        return Http::withBasicAuth((string) self::key(), (string) self::secret())
            ->acceptJson()
            ->connectTimeout((int) self::config('connect_timeout') ?: 5)
            ->timeout((int) self::config('timeout') ?: 20);
    }

    private static function fail(string $what, ?string $description, int $status): never
    {
        Log::error("Razorpay could not {$what}.", ['status' => $status, 'description' => $description]);

        throw new RuntimeException($description ?: "Razorpay could not {$what}.");
    }

    private static function secret(): ?string
    {
        return self::config('secret');
    }

    private static function config(string $key): ?string
    {
        $value = config("services.razorpay.{$key}");

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
