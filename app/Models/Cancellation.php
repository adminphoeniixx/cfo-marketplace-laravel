<?php

namespace App\Models;

use Database\Factories\CancellationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $order_id
 * @property string $number
 * @property string $status
 * @property string $reason
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $created_at
 */
class Cancellation extends Model
{
    /** @use HasFactory<CancellationFactory> */
    use HasFactory;

    public const REASONS = [
        'customer_changed_mind' => 'Customer changed their mind',
        'ordered_by_mistake' => 'Ordered by mistake',
        'found_cheaper' => 'Found a better price elsewhere',
        'delivery_too_slow' => 'Delivery taking too long',
        'out_of_stock' => 'Item out of stock',
        'address_issue' => 'Delivery address issue',
        'duplicate_order' => 'Duplicate order',
        'other' => 'Other',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'restock' => 'boolean',
            'refund_requested' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    public static function nextNumber(): string
    {
        $last = static::orderByDesc('id')->value('id') ?? 0;

        return 'CAN-'.str_pad((string) ($last + 1), 5, '0', STR_PAD_LEFT);
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

    /**
     * @return HasMany<CancellationItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CancellationItem::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
