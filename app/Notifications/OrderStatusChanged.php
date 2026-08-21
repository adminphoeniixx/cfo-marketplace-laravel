<?php

namespace App\Notifications;

use App\Models\Order;

/**
 * The marketplace moving an order out from under the seller.
 *
 * Only the transitions that change what a seller should do or is owed are
 * announced — see `worthTelling()`. Shipping is left out because that is
 * normally the seller's own doing, and `refunded` has `RefundDecided`.
 */
class OrderStatusChanged extends AdminNotification
{
    public function __construct(private readonly Order $order) {}

    /**
     * Whether a move from one status to another is worth an alert.
     */
    public static function worthTelling(string $from, string $to): bool
    {
        return $from !== $to && in_array($to, ['cancelled', 'on_hold', 'completed'], true);
    }

    public static function section(): string
    {
        return 'orders';
    }

    public function title(): string
    {
        return match ($this->order->status) {
            'cancelled' => "Order {$this->order->number} was cancelled",
            'on_hold' => "Order {$this->order->number} is on hold",
            default => "Order {$this->order->number} was delivered",
        };
    }

    public function body(): string
    {
        return match ($this->order->status) {
            'cancelled' => 'Stop packing this one — the marketplace cancelled it.',
            'on_hold' => 'Hold off packing until this is sorted out.',
            default => 'The earnings on this order are now settled.',
        };
    }

    public function url(): string
    {
        return "/admin/orders/{$this->order->id}";
    }

    public function tone(): string
    {
        return match ($this->order->status) {
            'cancelled' => 'danger',
            'on_hold' => 'warning',
            default => 'success',
        };
    }

    /**
     * Both sellers on a shared basket need to stop packing.
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
