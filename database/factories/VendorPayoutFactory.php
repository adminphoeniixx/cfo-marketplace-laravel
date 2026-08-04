<?php

namespace Database\Factories;

use App\Models\Vendor;
use App\Models\VendorPayout;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VendorPayout>
 */
class VendorPayoutFactory extends Factory
{
    protected $model = VendorPayout::class;

    public function definition(): array
    {
        $gross = fake()->randomFloat(2, 5000, 200000);
        $commission = round($gross * 0.1, 2);

        return [
            'number' => 'PO-'.fake()->unique()->numerify('#####'),
            'vendor_id' => Vendor::factory(),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'gross_sales' => $gross,
            'commission_amount' => $commission,
            'refunded_amount' => 0,
            'adjustment_amount' => 0,
            'net_amount' => $gross - $commission,
            'orders_count' => fake()->numberBetween(1, 40),
            'status' => 'pending',
            'method' => 'bank',
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => ['status' => 'paid', 'paid_at' => now()]);
    }
}
