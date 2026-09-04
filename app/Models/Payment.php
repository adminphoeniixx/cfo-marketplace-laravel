<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attempt at paying for an order.
 *
 * The order carries the receipt the shopper is shown (`transaction_id`); this
 * carries the trail behind it. A row is written when the app opens an intent
 * and settled by whichever of the two paths gets there first — the app's own
 * `verify` call, or the gateway's webhook. Because `gateway_order_id` is
 * unique and `markPaid()` is a no-op on an already-paid row, a replayed
 * webhook cannot capture twice.
 *
 * @property Carbon|null $paid_at
 */
class Payment extends Model
{
    public const STATUSES = ['created', 'paid', 'failed'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'meta' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /**
     * Settle this attempt, and the order with it.
     *
     * Idempotent by design: the webhook and the app's verify call race each
     * other on a normal, healthy checkout, and the loser must not double-count
     * anything.
     *
     * @param  array<string, mixed>  $meta
     */
    public function markPaid(string $gatewayPaymentId, ?string $method = null, array $meta = []): bool
    {
        if ($this->isPaid()) {
            return false;
        }

        $this->update([
            'status' => 'paid',
            'gateway_payment_id' => $gatewayPaymentId,
            'method' => $method ?? $this->method,
            'meta' => [...(array) $this->meta, ...$meta],
            'paid_at' => now(),
        ]);

        $order = $this->order;

        // Cash on delivery never reaches here, so anything that does is money
        // the marketplace now holds.
        $order->update([
            'payment_status' => 'paid',
            'transaction_id' => $gatewayPaymentId,
            'paid_at' => $order->paid_at ?? now(),
            'status' => $order->status === 'pending' ? 'processing' : $order->status,
        ]);

        $order->recordEvent(
            'payment',
            'Payment received',
            "{$gatewayPaymentId} · ".number_format((float) $this->amount, 2),
            ['gateway' => $this->gateway, 'gateway_order_id' => $this->gateway_order_id],
        );

        return true;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function markFailed(?string $reason = null, array $meta = []): void
    {
        if ($this->isPaid()) {
            return;
        }

        $this->update([
            'status' => 'failed',
            'failure_reason' => $reason,
            'meta' => [...(array) $this->meta, ...$meta],
        ]);

        $this->order->recordEvent('payment', 'Payment failed', $reason, [
            'gateway' => $this->gateway,
            'gateway_order_id' => $this->gateway_order_id,
        ]);
    }
}
