<?php

namespace Database\Factories;

use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vendor>
 */
class VendorFactory extends Factory
{
    protected $model = Vendor::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'store_email' => fake()->unique()->companyEmail(),
            'phone' => fake()->numerify('98########'),
            'description' => fake()->sentence(),
            'status' => 'approved',
            'commission_type' => 'percentage',
            'commission_rate' => fake()->randomFloat(2, 5, 20),
            'contact_name' => fake()->name(),
            'address_line1' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => fake()->randomElement(['Maharashtra', 'Karnataka', 'Delhi', 'Gujarat']),
            'postcode' => fake()->numerify('4#####'),
            'country' => 'IN',
            'payout_method' => 'bank',
            'approved_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => 'pending', 'approved_at' => null]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => 'suspended']);
    }
}
