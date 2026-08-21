<?php

namespace App\Notifications;

use App\Models\Refund;

class RefundRequested extends AdminNotification
{
    public function __construct(private readonly Refund $refund) {}

    public static function section(): string
    {
        return 'refunds';
    }

    public function title(): string
    {
        return "Refund {$this->refund->number} needs review";
    }

    public function body(): string
    {
        $total = number_format((float) $this->refund->total_amount, 2);
        $order = $this->refund->order->number;

        return "₹{$total} against {$order}.";
    }

    public function url(): string
    {
        return "/admin/refunds/{$this->refund->id}";
    }

    public function tone(): string
    {
        return 'critical';
    }

    /**
     * The stores whose lines this concerns — read off the request's own items,
     * not the order's first line, which on a shared basket is often somebody
     * else entirely.
     *
     * @return list<int>
     */
    public function vendorIds(): array
    {
        return array_values($this->refund->items
            ->map(fn ($line) => $line->orderItem?->vendor_id)
            ->filter()->unique()
            ->map(fn ($id) => (int) $id)->all());
    }
}
