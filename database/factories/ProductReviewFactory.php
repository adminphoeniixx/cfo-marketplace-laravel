<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductReview;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductReview>
 */
class ProductReviewFactory extends Factory
{
    protected $model = ProductReview::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'customer_id' => Customer::factory(),
            'rating' => fake()->numberBetween(3, 5),
            'title' => fake()->sentence(3),
            'body' => fake()->sentence(12),
            'status' => 'published',
            'is_verified' => true,
        ];
    }
}
