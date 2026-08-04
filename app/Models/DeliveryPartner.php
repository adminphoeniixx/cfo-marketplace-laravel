<?php

namespace App\Models;

use Database\Factories\DeliveryPartnerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
