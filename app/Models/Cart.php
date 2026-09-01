<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An open basket. One per signed-in customer, plus one per signed-out device
 * keyed by `token` — signing in merges the second into the first.
 */
class Cart extends Model
{
    protected $guarded = [];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<CartItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * Lines that count towards the total. "Saved for later" stays on the
     * basket but is not being bought right now.
     *
     * @return HasMany<CartItem, $this>
     */
    public function activeItems(): HasMany
    {
        return $this->items()->where('saved_for_later', false);
    }
}
