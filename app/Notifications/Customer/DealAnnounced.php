<?php

namespace App\Notifications\Customer;

/**
 * The one notification nobody asked for individually.
 *
 * Everything else here is a consequence of something the shopper did — they
 * ordered, they asked for a refund, they raised a ticket. This is the
 * marketplace speaking first, which is why it is the only one gated on a
 * preference the shopper can switch off without losing anything they need,
 * and why it is written by hand in the panel rather than fired by a status
 * change.
 */
class DealAnnounced extends ShopperNotification
{
    public function __construct(
        private readonly string $heading,
        private readonly string $message,
        private readonly ?string $target = null,
    ) {}

    public static function kind(): string
    {
        return 'deal';
    }

    /** Off by preference, and honoured — this is marketing, not a receipt. */
    public function pushPreference(): ?string
    {
        return 'notify_deals';
    }

    public function title(): string
    {
        return $this->heading;
    }

    public function body(): string
    {
        return $this->message;
    }

    public function link(): ?string
    {
        return $this->target;
    }
}
