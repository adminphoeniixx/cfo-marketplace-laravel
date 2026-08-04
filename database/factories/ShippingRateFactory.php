<?php

namespace Database\Factories;

use App\Models\ShippingRate;
use App\Models\ShippingZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShippingRate>
 */
class ShippingRateFactory extends Factory
{
    protected $model = ShippingRate::class;

    public function definition(): array
    {
        return [
            'shipping_zone_id' => ShippingZone::factory(),
            'vendor_id' => null,
            'name' => 'Standard Delivery',
            'type' => 'flat',
            'rate' => fake()->randomFloat(2, 20, 120),
            'per_item_rate' => 0,
            'per_kg_rate' => 0,
            'free_above_amount' => 999,
            'delivery_days_min' => 2,
            'delivery_days_max' => 6,
            'is_active' => true,
            'position' => 0,
        ];
    }
}
