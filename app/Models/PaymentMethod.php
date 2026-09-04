<?php

namespace App\Models;

use Database\Factories\PaymentMethodFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class PaymentMethod extends Model
{
    /** @use HasFactory<PaymentMethodFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * Orders are matched on the stored name, because that is what an order
     * records — the label at the time it was placed, not a foreign key.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'payment_method', 'name');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The glyph the app draws beside this method.
     *
     * The app used to keep its own code→emoji table, which meant a method the
     * admin added showed up in the list with nothing next to it. The column is
     * the admin's override and the map below is the floor, so every method —
     * including one invented tomorrow — arrives with something to draw.
     */
    public function glyph(): string
    {
        return trim((string) $this->icon) ?: self::defaultIconFor((string) $this->code);
    }

    /**
     * Matched on shape rather than on one exact spelling: these codes are the
     * admin panel's to name, and 'cod' is not the only way to write cash on
     * delivery.
     */
    public static function defaultIconFor(string $code): string
    {
        return match (true) {
            str_contains($code, 'upi') => '⚡',
            str_contains($code, 'card') => '💳',
            str_contains($code, 'bank') => '🏦',
            self::isPayOnDelivery($code) => '💵',
            str_contains($code, 'wallet') => '👛',
            default => '💰',
        };
    }

    /**
     * Whether the marketplace is taking cash on delivery at all right now.
     *
     * The "COD" filter is two facts, not one: the store has to handle cash and
     * the marketplace has to still offer it. With the method switched off in
     * the panel, the chip matches nothing rather than lying.
     */
    public static function payOnDeliveryIsOffered(): bool
    {
        return self::active()->get(['code'])
            ->contains(fn (self $method) => self::isPayOnDelivery($method->code));
    }

    /**
     * Cash on delivery, whatever the panel called it.
     */
    public static function isPayOnDelivery(?string $code): bool
    {
        return $code !== null && (str_contains($code, 'cash') || str_contains($code, 'cod'));
    }

    /**
     * The code behind a label an order recorded, or null when nothing matches
     * — a method deleted since, or a label typed in by hand.
     */
    public static function codeForName(?string $name): ?string
    {
        if (empty($name)) {
            return null;
        }

        return self::byName()->get($name)?->code;
    }

    /**
     * The glyph for a label an order recorded.
     */
    public static function iconForName(?string $name): ?string
    {
        if (empty($name)) {
            return null;
        }

        return self::byName()->get($name)?->glyph();
    }

    /**
     * Every method keyed by the name orders record.
     *
     * Memoised per request, not per process: an order list resolves one of
     * these per row, but the workers are long-lived under Octane and a method
     * added in the admin panel has to show up on the very next request.
     *
     * @return Collection<string, self>
     */
    private static function byName(): Collection
    {
        return once(fn () => self::query()->get(['id', 'name', 'code', 'icon'])->keyBy('name'));
    }
}
