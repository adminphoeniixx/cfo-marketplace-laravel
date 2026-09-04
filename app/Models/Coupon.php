<?php

namespace App\Models;

use Database\Factories\CouponFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A code the shopper can apply themselves.
 *
 * Three kinds, and the difference matters at checkout: `flat` and `percent`
 * come off the items, `free_shipping` comes off the delivery line. Nothing
 * here touches money — `discountOn()` says what it is worth and the checkout
 * decides what to do with it.
 */
class Coupon extends Model
{
    /** @use HasFactory<CouponFactory> */
    use HasFactory;

    public const TYPES = ['flat', 'percent', 'free_shipping'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'min_spend' => 'decimal:2',
            'max_discount' => 'decimal:2',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()))
            ->where(fn ($q) => $q->whereNull('usage_limit')->orWhereColumn('used_count', '<', 'usage_limit'));
    }

    /** Why this code cannot be used on a basket of this size, if it cannot. */
    public function rejectionFor(float $subtotal): ?string
    {
        if ($subtotal < (float) $this->min_spend) {
            return 'Add ₹'.number_format((float) $this->min_spend - $subtotal, 0).' more to use '.$this->code.'.';
        }

        return null;
    }

    /** What comes off the items. Free-shipping codes take nothing off here. */
    public function discountOn(float $subtotal): float
    {
        if ($subtotal < (float) $this->min_spend) {
            return 0.0;
        }

        $discount = match ($this->type) {
            'flat' => (float) $this->value,
            'percent' => $subtotal * (float) $this->value / 100,
            default => 0.0,
        };

        if ($this->max_discount !== null) {
            $discount = min($discount, (float) $this->max_discount);
        }

        return round(min($discount, $subtotal), 2);
    }

    /**
     * What this code is worth on a basket — items and delivery together.
     *
     * Zero is a real answer: a free-shipping code on a basket that already
     * ships free takes nothing off, and the shopper deserves to be told that
     * rather than left watching a total that does not move.
     */
    public function worthOn(float $subtotal, float $shipping): float
    {
        return round(
            $this->discountOn($subtotal) + ($this->coversShipping($subtotal) ? max($shipping, 0) : 0),
            2,
        );
    }

    public function coversShipping(float $subtotal): bool
    {
        return $this->type === 'free_shipping' && $subtotal >= (float) $this->min_spend;
    }
}
