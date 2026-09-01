<?php

namespace Database\Factories;

use App\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Coupon>
 */
class CouponFactory extends Factory
{
    protected $model = Coupon::class;

    public function definition(): array
    {
        return [
            'code' => mb_strtoupper(fake()->unique()->lexify('SAVE???')),
            'description' => 'Test coupon',
            'type' => 'flat',
            'value' => 100,
            'min_spend' => 0,
            'is_active' => true,
        ];
    }

    public function percent(float $value = 10): static
    {
        return $this->state(fn () => ['type' => 'percent', 'value' => $value]);
    }

    public function freeShipping(): static
    {
        return $this->state(fn () => ['type' => 'free_shipping', 'value' => 0]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }
}
