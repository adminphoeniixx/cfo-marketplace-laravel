<?php

namespace Database\Factories;

use App\Models\TaxClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxClass>
 */
class TaxClassFactory extends Factory
{
    protected $model = TaxClass::class;

    public function definition(): array
    {
        return [
            'name' => 'GST '.fake()->unique()->numberBetween(1, 99),
            'description' => fake()->sentence(),
            'is_default' => false,
            'is_active' => true,
        ];
    }
}
