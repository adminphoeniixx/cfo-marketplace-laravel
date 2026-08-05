<?php

namespace App\Notifications;

use App\Models\Order;

class OrderPlaced extends AdminNotification
{
    public function __construct(private readonly Order $order) {}

    public static function section(): string
    {
        return 'orders';
    }

    public function title(): string
    {
        return "New order {$this->order->number}";
    }

    public function body(): string
    {
        $total = number_format((float) $this->order->grand_total, 2);
        $name = $this->order->customer?->first_name;

        return $name
            ? "₹{$total} from {$name}."
            : "₹{$total}.";
    }

    public function url(): string
    {
        return "/admin/orders/{$this->order->id}";
    }

    public function tone(): string
    {
        return 'success';
    }

    public function vendorId(): ?int
    {
        return $this->order->items->first()?->vendor_id;
    }
}
