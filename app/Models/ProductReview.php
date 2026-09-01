<?php

namespace App\Models;

use Database\Factories\ProductReviewFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductReview extends Model
{
    /** @use HasFactory<ProductReviewFactory> */
    use HasFactory;

    public const STATUSES = ['published', 'pending', 'hidden'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'is_verified' => 'boolean',
        ];
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Reviews show a first name and a last initial, never the full name.
     */
    public function authorName(): string
    {
        $customer = $this->customer;

        if (! $customer) {
            return 'Verified buyer';
        }

        $initial = $customer->last_name ? ' '.mb_substr($customer->last_name, 0, 1).'.' : '';

        return $customer->first_name.$initial;
    }
}
