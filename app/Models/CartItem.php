<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property Product $product
 * @property ProductVariant|null $variant Null on a simple product; the column is nullable.
 */
class CartItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['saved_for_later' => 'boolean'];
    }

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** What one of these costs today — the variant's price wins when there is one. */
    public function unitPrice(): float
    {
        return (float) ($this->variant->price ?? $this->product->price);
    }

    /** How many of this line the seller can actually ship right now. */
    public function availableStock(): int
    {
        if (! $this->product->track_inventory || $this->product->allow_backorder) {
            return PHP_INT_MAX;
        }

        return (int) ($this->variant->stock_quantity ?? $this->product->stock_quantity);
    }
}
