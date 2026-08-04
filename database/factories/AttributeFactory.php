<?php

namespace Database\Factories;

use App\Models\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attribute>
 */
class AttributeFactory extends Factory
{
    protected $model = Attribute::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'type' => 'dropdown',
            'is_variant' => true,
            'is_filterable' => true,
            'is_active' => true,
            'position' => fake()->numberBetween(0, 10),
        ];
    }
}
