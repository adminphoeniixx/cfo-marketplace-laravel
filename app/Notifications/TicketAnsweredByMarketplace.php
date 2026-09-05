<?php

namespace App\Notifications;

use App\Models\Ticket;

/**
 * The marketplace has answered a seller.
 *
 * The shopper-facing `TicketAnswered` cannot serve here: it is addressed to a
 * `Customer`, and a store's ticket has no shopper in it at all.
 */
class TicketAnsweredByMarketplace extends AdminNotification
{
    public function __construct(private readonly Ticket $ticket) {}

    public static function section(): string
    {
        return 'tickets';
    }

    public function title(): string
    {
        return "{$this->ticket->number} · the marketplace replied";
    }

    public function body(): string
    {
        return 'There is an answer on "'.$this->ticket->subject.'".';
    }

    public function url(): string
    {
        return "/admin/tickets/{$this->ticket->id}";
    }

    public function vendorId(): ?int
    {
        return $this->ticket->vendor_id;
    }
}
