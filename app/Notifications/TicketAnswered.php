<?php

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Support has answered — told to the shopper, not to staff.
 *
 * Deliberately not an `AdminNotification`: that base class is addressed to
 * people with a role and a section, and a shopper has neither. This goes to
 * the app's own notification feed and to their inbox, because somebody who
 * wrote in on Monday is not necessarily holding the app open on Tuesday.
 */
class TicketAnswered extends Notification
{
    public function __construct(private readonly Ticket $ticket, private readonly string $reply) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Support replied about '.$this->ticket->number,
            'body' => str($this->reply)->limit(120)->toString(),
            'kind' => 'ticket',
            'link' => '/support/tickets/'.$this->ticket->number,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Re: {$this->ticket->subject} ({$this->ticket->number})")
            ->greeting('Hello '.$this->ticket->customerName('there').',')
            ->line('Somebody has replied to your support request:')
            ->line($this->reply)
            // Answered in the app rather than by email, so the conversation
            // stays in one place instead of splitting across two.
            ->line('You can reply in the app, under Help → My requests.')
            ->salutation('— '.config('app.name'));
    }
}
