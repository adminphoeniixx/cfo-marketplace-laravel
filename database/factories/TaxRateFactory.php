<?php

namespace Database\Factories;

use App\Models\TaxClass;
use App\Models\TaxRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxRate>
 */
class TaxRateFactory extends Factory
{
    protected $model = TaxRate::class;

    public function definition(): array
    {
        return [
            'tax_class_id' => TaxClass::factory(),
            'name' => fake()->randomElement(['IGST', 'CGST', 'SGST']),
            'country' => 'IN',
            'state' => null,
            'rate' => fake()->randomElement([5, 12, 18, 28]),
            'priority' => 1,
            'is_compound' => false,
            'applies_to_shipping' => false,
            'is_active' => true,
        ];
    }
}
