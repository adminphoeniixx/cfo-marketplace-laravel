<?php

namespace App\Models;

use App\Services\Emoji;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int|null $vendor_id
 * @property int|null $category_id
 * @property int|null $tax_class_id
 * @property string $name
 * @property string $slug
 * @property string|null $sku
 * @property string $type
 * @property string $status
 * @property int $stock_quantity
 * @property int $low_stock_threshold
 * @property array<int, string>|null $tags
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, SoftDeletes;

    public const STATUSES = ['draft', 'active', 'archived'];

    protected $guarded = [];

    /**
     * Whether the shopper asking has this saved. Set by the customer API when
     * a token is present, left null when nobody is signed in — not a column,
     * and never persisted.
     */
    public ?bool $is_wishlisted = null;

    /**
     * Five buckets, five stars down to one, each `['rating' => int,
     * 'count' => int]`. Filled in by the product endpoint so the histogram is
     * one query rather than five.
     *
     * @var array<int, array{rating: int, count: int}>|null
     */
    public ?array $rating_breakdown = null;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'weight' => 'decimal:3',
            'rating' => 'decimal:2',
            'tags' => 'array',
            'track_inventory' => 'boolean',
            'allow_backorder' => 'boolean',
            'requires_shipping' => 'boolean',
            'is_featured' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            $product->slug ??= static::uniqueSlug($product->name);
        });
    }

    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 2;

        while (static::withTrashed()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * A glyph for a product with no photograph, so the app never draws an
     * empty tile. Its own name decides it where the words give anything away,
     * and its category is the fallback — read off the relation only where one
     * is already loaded, because a listing draws hundreds of these.
     */
    public function emoji(): string
    {
        $category = $this->relationLoaded('category') ? $this->category : null;

        return Emoji::forProduct($this->name, $category?->icon, $category?->name);
    }

    /**
     * @return BelongsTo<TaxClass, $this>
     */
    public function taxClass(): BelongsTo
    {
        return $this->belongsTo(TaxClass::class);
    }

    /**
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    /**
     * @return BelongsToMany<Attribute, $this>
     */
    public function attributes(): BelongsToMany
    {
        return $this->belongsToMany(Attribute::class)->withPivot(['used_for_variants', 'position']);
    }

    /**
     * @return HasMany<ProductImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    /**
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position');
    }

    /**
     * @return HasMany<ProductReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    /**
     * Recalculate the cached `rating` column from published reviews.
     *
     * The column is what listing and search sort on, so it is written once
     * here rather than averaged on every read.
     */
    public function refreshRating(): void
    {
        $published = $this->reviews()->published();

        $this->forceFill([
            'rating' => round((float) $published->avg('rating'), 2),
        ])->save();
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Can a shopper buy this right now?
     *
     * Backorders count as in stock: the seller has said they will make more.
     */
    public function isInStock(): bool
    {
        return ! $this->track_inventory
            || $this->allow_backorder
            || $this->stock_quantity > 0;
    }

    public function getStockStatusAttribute(): string
    {
        if (! $this->track_inventory) {
            return 'in_stock';
        }

        if ($this->stock_quantity <= 0) {
            return 'out_of_stock';
        }

        return $this->stock_quantity <= $this->low_stock_threshold ? 'low_stock' : 'in_stock';
    }
}
