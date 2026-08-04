<?php

namespace Database\Factories;

use App\Models\ShippingZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShippingZone>
 */
class ShippingZoneFactory extends Factory
{
    protected $model = ShippingZone::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city().' Zone',
            'description' => fake()->sentence(),
            'countries' => ['IN'],
            'states' => ['Maharashtra', 'Karnataka'],
            'postcodes' => null,
            'is_active' => true,
            'position' => fake()->numberBetween(0, 10),
        ];
    }
}
