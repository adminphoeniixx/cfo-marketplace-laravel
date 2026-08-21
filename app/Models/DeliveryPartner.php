<?php

namespace App\Models;

use Database\Factories\DeliveryPartnerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class DeliveryPartner extends Model
{
    /** @use HasFactory<DeliveryPartnerFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * Orders record the courier's name at fulfilment time, not a foreign key.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'carrier', 'name');
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
     * The tracking link for an order, looked up by the courier name the order
     * recorded at fulfilment time.
     *
     * Orders carry the name rather than a foreign key, so this has to match on
     * it.
     */
    public static function trackingUrlFrom(?string $carrier, ?string $trackingNumber): ?string
    {
        if (empty($carrier) || empty($trackingNumber)) {
            return null;
        }

        return self::byName()->get($carrier)?->trackingUrlFor($trackingNumber);
    }

    /**
     * Every partner keyed by the name orders record.
     *
     * Memoised per request, not per process: an order list resolves one of
     * these per row, but the workers are long-lived under Octane and a partner
     * added in the admin panel has to show up on the very next request.
     *
     * @return Collection<string, self>
     */
    private static function byName(): Collection
    {
        return once(fn () => self::query()->get(['id', 'name', 'tracking_url'])->keyBy('name'));
    }

    /**
     * Public tracking link for a consignment, or null when this partner has no
     * tracking URL configured.
     */
    public function trackingUrlFor(?string $trackingNumber): ?string
    {
        if (empty($this->tracking_url) || empty($trackingNumber)) {
            return null;
        }

        return str_replace('{tracking}', rawurlencode($trackingNumber), $this->tracking_url);
    }
}
