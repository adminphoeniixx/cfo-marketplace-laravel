<?php

namespace App\Notifications;

use App\Models\Order;

/**
 * A parcel that has stopped going where it was meant to.
 *
 * The four states here have nothing in common except the only thing that
 * matters: waiting does not fix any of them. A shopper who was out needs
 * calling, a parcel on its way back needs receiving, a lost one needs
 * claiming. Left unannounced they all read as "shipped" for ever, which is how
 * a marketplace discovers a returned parcel a month later from a stock count.
 */
class ParcelNeedsAttention extends AdminNotification
{
    public function __construct(
        private readonly Order $order,
        private readonly string $shipmentStatus,
    ) {}

    public static function section(): string
    {
        return 'orders';
    }

    public function title(): string
    {
        return match ($this->shipmentStatus) {
            'undelivered' => "Delivery failed for order {$this->order->number}",
            'returning' => "Order {$this->order->number} is coming back",
            'returned' => "Order {$this->order->number} is back with you",
            default => "Order {$this->order->number} has been lost",
        };
    }

    public function body(): string
    {
        $attempts = (int) $this->order->delivery_attempts;

        return match ($this->shipmentStatus) {
            'undelivered' => $attempts > 1
                ? "The courier has tried {$attempts} times. Call the shopper before it comes back."
                : 'The courier could not hand it over. Call the shopper before it is tried again.',
            'returning' => 'The courier is bringing it back to you. Expect it, and restock it when it lands.',
            'returned' => 'It is back. Restock it, and settle the money with the marketplace.',
            default => 'The courier cannot find this parcel. Raise a claim with them.',
        };
    }

    public function url(): string
    {
        return "/admin/orders/{$this->order->id}";
    }

    public function tone(): string
    {
        return $this->shipmentStatus === 'lost' ? 'danger' : 'warning';
    }

    /**
     * Every seller with something in the box, because a shared basket travels
     * as one parcel and comes back as one too.
     *
     * @return list<int>
     */
    public function vendorIds(): array
    {
        return array_values($this->order->items
            ->pluck('vendor_id')->filter()->unique()
            ->map(fn ($id) => (int) $id)->all());
    }
}
