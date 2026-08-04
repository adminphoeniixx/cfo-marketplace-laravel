<?php

namespace Database\Factories;

use App\Models\Cancellation;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cancellation>
 */
class CancellationFactory extends Factory
{
    protected $model = Cancellation::class;

    public function definition(): array
    {
        return [
            'number' => 'CAN-'.fake()->unique()->numerify('#####'),
            'order_id' => Order::factory(),
            'customer_id' => null,
            'scope' => 'partial',
            'reason' => array_rand(Cancellation::REASONS),
            'note' => fake()->sentence(),
            'status' => 'pending',
            'total_amount' => fake()->randomFloat(2, 200, 5000),
            'restock' => true,
            'refund_requested' => false,
            'requested_by' => 'customer',
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => 'approved', 'reviewed_at' => now()]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => ['status' => 'rejected', 'reviewed_at' => now()]);
    }
}
