<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One taxable supply, written down.
 *
 * @property int $id
 * @property string $number
 * @property string $type
 * @property string $financial_year
 * @property int $sequence
 * @property int|null $order_id
 * @property int $vendor_id
 * @property int|null $vendor_payout_id
 * @property array<string, mixed> $snapshot
 * @property Carbon $issued_at
 */
class Invoice extends Model
{
    /** Goods, seller to shopper. */
    public const TAX = 'tax';

    /** The platform, marketplace to seller. */
    public const COMMISSION = 'commission';

    public const TYPES = [self::TAX, self::COMMISSION];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'shipping_total' => 'decimal:2',
            'cgst_total' => 'decimal:2',
            'sgst_total' => 'decimal:2',
            'igst_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'issued_at' => 'datetime',
        ];
    }

    /**
     * The Indian financial year a date falls in, as `2026-27`.
     *
     * April to March, which is not the calendar year and not negotiable: a GST
     * invoice series restarts on the 1st of April, so this is what a number is
     * unique within.
     */
    public static function financialYearFor(CarbonInterface $date): string
    {
        $start = $date->month >= 4 ? $date->year : $date->year - 1;

        return $start.'-'.substr((string) ($start + 1), 2);
    }

    /**
     * The next number in a series, and the sequence that produced it.
     *
     * Two sellers may hold the same sequence number in the same year — they
     * are separate suppliers keeping separate books — so the series is per
     * vendor for a tax invoice, and marketplace-wide for a commission one.
     * Callers must already be inside a transaction; the row lock is what stops
     * two orders invoiced in the same second from claiming one number.
     *
     * @return array{string, int}
     */
    public static function nextInSeries(string $type, string $financialYear, int $vendorId, string $prefix): array
    {
        $scope = static::query()
            ->where('type', $type)
            ->where('financial_year', $financialYear);

        // A commission series belongs to the marketplace, so it does not
        // restart per seller; a tax series does.
        if ($type === self::TAX) {
            $scope->where('vendor_id', $vendorId);
        }

        $last = (int) $scope->lockForUpdate()->max('sequence');
        $next = $last + 1;

        $number = $type === self::TAX
            ? sprintf('%s/%s/V%d/%05d', $prefix, $financialYear, $vendorId, $next)
            : sprintf('%s/%s/%05d', $prefix, $financialYear, $next);

        return [$number, $next];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return BelongsTo<VendorPayout, $this>
     */
    public function payout(): BelongsTo
    {
        return $this->belongsTo(VendorPayout::class, 'vendor_payout_id');
    }
}
