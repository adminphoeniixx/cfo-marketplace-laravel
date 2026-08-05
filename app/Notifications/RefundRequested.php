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

    public function vendorId(): ?int
    {
        return $this->refund->order->items->first()?->vendor_id;
    }
}
