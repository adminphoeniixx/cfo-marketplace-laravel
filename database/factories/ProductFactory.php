<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $price = fake()->randomFloat(2, 199, 9999);

        return [
            'vendor_id' => Vendor::factory(),
            'category_id' => Category::factory(),
            'tax_class_id' => null,
            'name' => fake()->unique()->words(3, true),
            'sku' => fake()->unique()->bothify('SKU-####-???'),
            'type' => 'simple',
            'short_description' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'price' => $price,
            'compare_at_price' => round($price * 1.2, 2),
            'cost_price' => round($price * 0.6, 2),
            'track_inventory' => true,
            // Comfortably above low_stock_threshold: tests that care about a low or
            // empty stock level set it explicitly (or use the outOfStock state), and
            // a random default below the threshold would make those assertions flaky.
            'stock_quantity' => fake()->numberBetween(20, 200),
            'low_stock_threshold' => 5,
            'weight' => fake()->randomFloat(3, 0.1, 5),
            'requires_shipping' => true,
            'status' => 'active',
            'is_featured' => false,
            'brand' => fake()->company(),
            'published_at' => now(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => 'draft', 'published_at' => null]);
    }

    public function outOfStock(): static
    {
        return $this->state(fn () => ['stock_quantity' => 0]);
    }
}
