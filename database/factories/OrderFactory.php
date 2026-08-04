<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 500, 20000);
        $tax = round($subtotal * 0.18, 2);
        $shipping = 79;

        return [
            'number' => '#'.fake()->unique()->numberBetween(1000, 999999),
            'customer_id' => Customer::factory(),
            'email' => fake()->safeEmail(),
            'phone' => fake()->numerify('9#########'),
            'status' => 'processing',
            'payment_status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'currency' => 'INR',
            'subtotal' => $subtotal,
            'discount_total' => 0,
            'tax_total' => $tax,
            'shipping_total' => $shipping,
            'grand_total' => $subtotal + $tax + $shipping,
            'refunded_total' => 0,
            'commission_total' => round($subtotal * 0.1, 2),
            'payment_method' => 'upi',
            'shipping_method' => 'Standard Delivery',
            'billing_address' => ['city' => fake()->city(), 'country' => 'IN'],
            'shipping_address' => ['city' => fake()->city(), 'country' => 'IN'],
            'placed_at' => now()->subDays(fake()->numberBetween(0, 30)),
            'paid_at' => now()->subDays(fake()->numberBetween(0, 30)),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => 'pending',
            'payment_status' => 'pending',
            'paid_at' => null,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);
    }
}
