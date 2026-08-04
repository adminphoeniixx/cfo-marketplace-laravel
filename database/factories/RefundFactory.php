<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Refund;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Refund>
 */
class RefundFactory extends Factory
{
    protected $model = Refund::class;

    public function definition(): array
    {
        $items = fake()->randomFloat(2, 200, 5000);

        return [
            'number' => 'REF-'.fake()->unique()->numerify('#####'),
            'order_id' => Order::factory(),
            'cancellation_id' => null,
            'customer_id' => null,
            'type' => 'partial',
            'reason' => array_rand(Refund::REASONS),
            'note' => fake()->sentence(),
            'status' => 'pending',
            'items_amount' => $items,
            'shipping_amount' => 0,
            'tax_amount' => 0,
            'adjustment_amount' => 0,
            'total_amount' => $items,
            'method' => 'original',
            'restock' => true,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => 'approved', 'reviewed_at' => now()]);
    }

    public function processed(): static
    {
        return $this->state(fn () => [
            'status' => 'processed',
            'reviewed_at' => now(),
            'processed_at' => now(),
        ]);
    }
}
