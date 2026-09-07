<?php

namespace App\Models;

use Database\Factories\WalletTransactionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $expires_at
 *
 * One movement of store credit.
 *
 * The balance is the sum of these rather than a column, so "where did this
 * come from" is a question the wallet screen can answer instead of a number
 * nobody can account for.
 */
class WalletTransaction extends Model
{
    /** @use HasFactory<WalletTransactionFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * What a movement is. `refund` and `adjustment` add; `spend` takes away
     * and is the only one that may be negative.
     */
    public const KINDS = ['refund', 'adjustment', 'spend'];

    /**
     * Credit that still counts: nothing that has lapsed.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()));
    }

    /**
     * What this shopper can spend today.
     */
    public static function balanceFor(int $customerId): float
    {
        return round((float) static::query()
            ->where('customer_id', $customerId)
            ->live()
            ->sum('amount'), 2);
    }

    /**
     * The same number, read under a lock.
     *
     * Two checkouts running at once would both read the old balance and both
     * spend it, and the ledger would go negative with nobody at fault. Callers
     * must already be inside a transaction; `lockForUpdate` is what makes the
     * second one wait for the first one's debit to land.
     */
    public static function lockedBalanceFor(int $customerId): float
    {
        return round((float) static::query()
            ->where('customer_id', $customerId)
            ->live()
            ->lockForUpdate()
            ->sum('amount'), 2);
    }
}
