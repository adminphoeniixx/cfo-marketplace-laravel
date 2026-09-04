<?php

namespace App\Models;

use Database\Factories\CustomerPaymentMethodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $expires_at
 *
 * A way one shopper has chosen to pay, saved for next time.
 *
 * Not to be confused with `PaymentMethod`, which is the marketplace's own list
 * of what it accepts at all. This is personal: a card the gateway holds a
 * token for, a UPI handle, a wallet. Nothing here can be charged on its own —
 * the number never reaches this marketplace.
 */
class CustomerPaymentMethod extends Model
{
    /** @use HasFactory<CustomerPaymentMethodFactory> */
    use HasFactory;

    public const TYPES = ['card', 'upi', 'wallet', 'netbanking'];

    protected $guarded = [];

    /**
     * The gateway's token is not the shopper's business, and not the app's
     * either: it is only ever used server-side to raise a charge.
     *
     * @var list<string>
     */
    protected $hidden = ['gateway_token'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'date',
            'is_default' => 'boolean',
            'verified' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * A card is finished at the end of the month it names.
     */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->endOfMonth()->isPast();
    }

    /**
     * Make this the one the checkout screen opens on, and the only one.
     */
    public function makeDefault(): void
    {
        static::where('customer_id', $this->customer_id)
            ->whereKeyNot($this->id)
            ->update(['is_default' => false]);

        $this->forceFill(['is_default' => true])->save();
    }
}
