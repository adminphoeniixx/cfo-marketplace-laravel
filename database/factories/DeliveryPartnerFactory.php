<?php

namespace Database\Factories;

use App\Models\DeliveryPartner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DeliveryPartner>
 */
class DeliveryPartnerFactory extends Factory
{
    protected $model = DeliveryPartner::class;

    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Delhivery', 'Blue Dart', 'Ekart', 'DTDC', 'Shadowfax', 'XpressBees', 'Ecom Express', 'India Post',
        ]);

        return [
            'name' => $name,
            'code' => Str::slug($name),
            'tracking_url' => 'https://'.Str::slug($name).'.test/track/{tracking}',
            'support_phone' => fake()->boolean(50) ? fake()->numerify('1800#######') : null,
            'notes' => null,
            'is_active' => true,
            'position' => fake()->numberBetween(0, 10),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
