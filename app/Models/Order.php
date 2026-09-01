<?php

namespace App\Models;

use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property array<string, mixed>|null $billing_address
 * @property array<string, mixed>|null $shipping_address
 * @property Carbon|null $placed_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $shipped_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $cancelled_at
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    public const STATUSES = ['pending', 'processing', 'on_hold', 'shipped', 'completed', 'cancelled', 'refunded'];

    public const PAYMENT_STATUSES = ['pending', 'paid', 'partially_refunded', 'refunded', 'failed'];

    public const FULFILLMENT_STATUSES = ['unfulfilled', 'partially_fulfilled', 'fulfilled'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'shipping_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'refunded_total' => 'decimal:2',
            'commission_total' => 'decimal:2',
            'billing_address' => 'array',
            'shipping_address' => 'array',
            'placed_at' => 'datetime',
            'paid_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public static function nextNumber(): string
    {
        $last = static::orderByDesc('id')->value('id') ?? 0;

        return '#'.str_pad((string) (1000 + $last + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<OrderEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class)->latest();
    }

    /**
     * @return HasMany<Cancellation, $this>
     */
    public function cancellations(): HasMany
    {
        return $this->hasMany(Cancellation::class);
    }

    /**
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * How long after delivery a shopper may still send something back.
     *
     * One window for the whole marketplace rather than a per-product one: the
     * shopper app quotes it on the product page before there is an order, and
     * two different windows on one basket would be unexplainable.
     */
    public const RETURN_WINDOW_DAYS = 7;

    /**
     * A shopper may call off an order the seller has not handed to a courier.
     * Once any of it has shipped, the way back is a return.
     */
    public function canBeCancelledByCustomer(): bool
    {
        return in_array($this->status, ['pending', 'processing', 'on_hold'], true)
            && $this->fulfillment_status === 'unfulfilled';
    }

    /**
     * Returns open on delivery and close after the window — and only for an
     * order that still has something on it that has not already gone back.
     */
    public function canBeReturnedByCustomer(): bool
    {
        if ($this->status !== 'completed' || ! $this->delivered_at) {
            return false;
        }

        return $this->delivered_at->diffInDays(now()) <= self::RETURN_WINDOW_DAYS;
    }

    public function getRefundableAmountAttribute(): float
    {
        return round((float) $this->grand_total - (float) $this->refunded_total, 2);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function recordEvent(string $type, string $title, ?string $body = null, array $meta = []): OrderEvent
    {
        $actor = auth()->user();

        return $this->events()->create([
            // `user_id` points at staff and sellers. A shopper is a `Customer`
            // — a different table with its own ids — so an event they caused
            // is recorded with no actor rather than with an id that would land
            // on whichever unrelated staff login shares that number.
            'user_id' => $actor instanceof User ? $actor->getKey() : null,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'meta' => $meta ?: null,
        ]);
    }
}
