<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerPaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerPaymentMethod>
 */
class CustomerPaymentMethodFactory extends Factory
{
    protected $model = CustomerPaymentMethod::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'type' => 'card',
            'label' => 'HDFC Debit',
            'masked_value' => '•••• 4242',
            'provider' => 'visa',
            'gateway' => 'razorpay',
            'expires_at' => now()->addYear()->startOfMonth(),
            'is_default' => false,
            'verified' => true,
        ];
    }

    public function upi(): static
    {
        return $this->state(fn () => [
            'type' => 'upi',
            'label' => 'Google Pay',
            'masked_value' => 'priya@okhdfc',
            'provider' => 'gpay',
            'expires_at' => null,
        ]);
    }
}
