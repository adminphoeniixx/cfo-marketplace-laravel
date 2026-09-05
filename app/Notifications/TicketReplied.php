<?php

namespace App\Notifications;

use App\Models\Ticket;

/**
 * A shopper has written back — so a ticket support thought it had finished
 * with is open again, and somebody should know without refreshing a list.
 */
class TicketReplied extends AdminNotification
{
    public function __construct(private readonly Ticket $ticket) {}

    public static function section(): string
    {
        return 'tickets';
    }

    public function title(): string
    {
        return "{$this->ticket->number} · new reply";
    }

    public function body(): string
    {
        $who = $this->ticket->customerName('The shopper');

        return $who.' replied to "'.$this->ticket->subject.'".';
    }

    public function url(): string
    {
        return "/admin/tickets/{$this->ticket->id}";
    }
}
