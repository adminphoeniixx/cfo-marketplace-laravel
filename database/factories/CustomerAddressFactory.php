<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerAddress>
 */
class CustomerAddressFactory extends Factory
{
    protected $model = CustomerAddress::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'label' => 'Home',
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'address_line1' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => 'Maharashtra',
            'postcode' => fake()->numerify('4#####'),
            'country' => 'IN',
            'phone' => fake()->numerify('9#########'),
            'is_default_billing' => false,
            'is_default_shipping' => false,
        ];
    }

    public function defaultAddress(): static
    {
        return $this->state(fn () => [
            'is_default_billing' => true,
            'is_default_shipping' => true,
        ]);
    }
}
