<?php

namespace Database\Factories;

use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketMessage>
 */
class TicketMessageFactory extends Factory
{
    protected $model = TicketMessage::class;

    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'body' => 'It was due on Tuesday and there is no update.',
            'is_internal' => false,
        ];
    }

    public function internal(): static
    {
        return $this->state(fn () => [
            'is_internal' => true,
            'body' => 'Spoke to the seller; they are posting a replacement.',
        ]);
    }
}
