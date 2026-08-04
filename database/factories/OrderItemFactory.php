<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        $unitPrice = fake()->randomFloat(2, 199, 4999);
        $quantity = fake()->numberBetween(1, 4);
        $lineTotal = $unitPrice * $quantity;
        $commission = round($lineTotal * 0.1, 2);

        return [
            'order_id' => Order::factory(),
            'product_id' => Product::factory(),
            'product_variant_id' => null,
            'vendor_id' => null,
            'name' => fake()->words(3, true),
            'sku' => fake()->bothify('SKU-####-???'),
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'quantity_cancelled' => 0,
            'quantity_refunded' => 0,
            'quantity_fulfilled' => 0,
            'discount_amount' => 0,
            'tax_rate' => 18,
            'tax_amount' => round($lineTotal * 0.18, 2),
            'total' => $lineTotal,
            'commission_rate' => 10,
            'commission_amount' => $commission,
            'vendor_earning' => $lineTotal - $commission,
            'fulfillment_status' => 'unfulfilled',
        ];
    }

    public function forOrder(Order $order): static
    {
        return $this->state(fn () => ['order_id' => $order->id]);
    }
}
