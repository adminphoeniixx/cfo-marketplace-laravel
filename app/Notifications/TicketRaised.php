<?php

namespace App\Notifications;

use App\Models\Ticket;

class TicketRaised extends AdminNotification
{
    public function __construct(private readonly Ticket $ticket) {}

    public static function section(): string
    {
        return 'tickets';
    }

    public function title(): string
    {
        return "{$this->ticket->number} · {$this->ticket->subject}";
    }

    public function body(): string
    {
        $who = $this->ticket->customerName('A shopper', 'first_name');

        return $who.' opened a ticket'
            .($this->ticket->order ? " about {$this->ticket->order->number}" : '').'.';
    }

    public function url(): string
    {
        return "/admin/tickets/{$this->ticket->id}";
    }

    public function tone(): string
    {
        // Somebody is waiting on a person, which is not the same kind of news
        // as an order having been placed.
        return 'attention';
    }
}
