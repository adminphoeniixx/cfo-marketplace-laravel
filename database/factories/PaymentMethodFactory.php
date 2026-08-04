<?php

namespace Database\Factories;

use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentMethod>
 */
class PaymentMethodFactory extends Factory
{
    protected $model = PaymentMethod::class;

    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'UPI', 'Credit Card', 'Debit Card', 'Net Banking', 'Cash on Delivery', 'Wallet', 'Bank Transfer', 'Cheque',
        ]);

        return [
            'name' => $name,
            'code' => Str::slug($name),
            'description' => fake()->boolean(40) ? fake()->sentence() : null,
            'is_active' => true,
            'position' => fake()->numberBetween(0, 10),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
