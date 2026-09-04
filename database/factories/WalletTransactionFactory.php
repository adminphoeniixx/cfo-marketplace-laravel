<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WalletTransaction>
 */
class WalletTransactionFactory extends Factory
{
    protected $model = WalletTransaction::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'amount' => 500,
            'kind' => 'refund',
            'description' => 'Refund for #1001',
        ];
    }
}
