<?php

namespace App\Notifications\Customer;

use App\Models\Order;

/**
 * The parcel moved, and the shopper hears about it.
 *
 * One class for every step rather than one per step, because to a shopper this
 * is a single story told in instalments — and because grouping them under one
 * tag lets "out for delivery" replace "in transit" on the lock screen instead
 * of stacking beneath it.
 *
 * Two sources feed it and they are not the same thing: `status` is the
 * marketplace's account of the sale, `shipment_status` is the courier's account
 * of the box. Where the courier has spoken it wins, because it is closer to
 * the door than we are.
 */
class OrderProgressed extends ShopperNotification
{
    public function __construct(
        private readonly Order $order,
        /**
         * The state it moved to. Which of the two fields moved decided whether
         * this is worth sending at all — see `worthTelling` — but not what it
         * says, because a shopper is being told about their parcel and not
         * about which of our columns changed.
         */
        private readonly string $to,
    ) {}

    /**
     * Whether this particular move is worth a shopper's attention.
     *
     * Most are not. "Processing" is what every paid order is a second after it
     * is placed, and a shopper who just tapped Pay does not need telling. The
     * list is short on purpose: a notification that arrives for everything
     * gets muted, and then the one that mattered is muted too.
     */
    public static function worthTelling(string $field, string $to): bool
    {
        return in_array($to, $field === 'shipment_status'
            ? ['in_transit', 'out_for_delivery', 'delivered', 'undelivered', 'returning', 'returned']
            : ['shipped', 'completed', 'cancelled', 'refunded'], true);
    }

    public static function kind(): string
    {
        return 'order';
    }

    /** Order updates are their own row on the shopper's settings screen. */
    public function pushPreference(): ?string
    {
        return 'notify_order_updates';
    }

    public function title(): string
    {
        return match ($this->to) {
            'in_transit' => 'Your order is on the way',
            'out_for_delivery' => 'Arriving today',
            'delivered', 'completed' => 'Delivered',
            'undelivered' => 'We could not deliver your order',
            'returning' => 'Your order is coming back to us',
            'returned' => 'Your order was returned',
            'shipped' => 'Your order has been packed',
            'cancelled' => 'Your order was cancelled',
            'refunded' => 'Your order was refunded',
            default => 'Your order was updated',
        };
    }

    public function body(): string
    {
        $number = $this->order->number;
        $carrier = $this->order->carrier;

        return match ($this->to) {
            'in_transit' => $carrier
                ? "{$number} has left the seller with {$carrier}."
                : "{$number} has left the seller.",
            'out_for_delivery' => "{$number} is out for delivery today. Keep your phone nearby.",
            'delivered', 'completed' => "{$number} has been delivered. Tell us how it went.",
            // Said plainly, because the shopper is the one who has to act.
            'undelivered' => "A delivery attempt for {$number} did not succeed. The courier will try again.",
            'returning' => "{$number} is on its way back to the seller. Any refund follows once it arrives.",
            'returned' => "{$number} is back with the seller.",
            'shipped' => "{$number} is packed and waiting for the courier.",
            'cancelled' => "{$number} has been cancelled. Anything already paid is refunded.",
            'refunded' => "The refund for {$number} has been settled.",
            default => "There is an update on {$number}.",
        };
    }

    public function link(): string
    {
        return '/orders/'.ltrim($this->order->number, '#').'/track';
    }

    /**
     * Grouped per order: a shopper waiting on two parcels must not have one
     * alert silently replace the other.
     */
    protected function groupTag(): string
    {
        return 'order-'.$this->order->getKey();
    }
}
