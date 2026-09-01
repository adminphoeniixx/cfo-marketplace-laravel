<?php

namespace App\Models;

use Database\Factories\RefundFactory;
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
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 */
class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
    use HasFactory;

    /** As for cancellations: `withdrawn` is the shopper's own retraction. */
    public const STATUSES = ['pending', 'approved', 'rejected', 'processed', 'withdrawn'];

    public const REASONS = [
        'damaged' => 'Item arrived damaged',
        'wrong_item' => 'Wrong item delivered',
        'not_as_described' => 'Not as described',
        'size_issue' => 'Size or fit issue',
        'quality_issue' => 'Quality not satisfactory',
        'late_delivery' => 'Delivered too late',
        'cancelled_order' => 'Order was cancelled',
        'other' => 'Other',
    ];

    public const METHODS = [
        'original' => 'Original payment method',
        'store_credit' => 'Store credit',
        'bank_transfer' => 'Bank transfer',
        'upi' => 'UPI',
        'manual' => 'Manual / offline',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'items_amount' => 'decimal:2',
            'shipping_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'adjustment_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'restock' => 'boolean',
            'reviewed_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public static function nextNumber(): string
    {
        $last = static::orderByDesc('id')->value('id') ?? 0;

        return 'REF-'.str_pad((string) ($last + 1), 5, '0', STR_PAD_LEFT);
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
     * @return BelongsTo<Cancellation, $this>
     */
    public function cancellation(): BelongsTo
    {
        return $this->belongsTo(Cancellation::class);
    }

    /**
     * @return HasMany<RefundItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(RefundItem::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
