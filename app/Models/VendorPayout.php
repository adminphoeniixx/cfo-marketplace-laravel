<?php

namespace App\Models;

use Database\Factories\VendorPayoutFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $vendor_id
 * @property string $number
 * @property string $status
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property Carbon|null $paid_at
 * @property Carbon|null $created_at
 */
class VendorPayout extends Model
{
    /** @use HasFactory<VendorPayoutFactory> */
    use HasFactory;

    public const STATUSES = ['pending', 'processing', 'paid', 'failed'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'gross_sales' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'adjustment_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public static function nextNumber(): string
    {
        $last = static::orderByDesc('id')->value('id') ?? 0;

        return 'PO-'.str_pad((string) ($last + 1), 5, '0', STR_PAD_LEFT);
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
