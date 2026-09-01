<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('9#########'),
            'status' => 'active',
            'accepts_marketing' => fake()->boolean(),
            'email_verified' => true,
            'total_spent' => 0,
            'orders_count' => 0,
        ];
    }

    /** A shopper who can sign in with an email and a password. */
    public function withPassword(string $password = 'a-good-password'): static
    {
        return $this->state(fn () => ['password' => $password]);
    }

    public function blocked(): static
    {
        return $this->state(fn () => ['status' => 'blocked']);
    }
}
