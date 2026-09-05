<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    public function definition(): array
    {
        return [
            'number' => 'TKT-'.fake()->unique()->numerify('#####'),
            'customer_id' => Customer::factory(),
            'subject' => 'My parcel has not arrived',
            'category' => 'delivery',
            'status' => 'open',
            'priority' => 'normal',
            'last_reply_at' => now(),
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'status' => 'resolved',
            'first_responded_at' => now()->subHour(),
        ]);
    }
}
