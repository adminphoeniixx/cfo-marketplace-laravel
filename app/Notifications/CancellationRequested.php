<?php

namespace App\Notifications;

use App\Models\Cancellation;

class CancellationRequested extends AdminNotification
{
    public function __construct(private readonly Cancellation $cancellation) {}

    public static function section(): string
    {
        return 'cancellations';
    }

    public function title(): string
    {
        return "Cancellation {$this->cancellation->number} needs review";
    }

    public function body(): string
    {
        $order = $this->cancellation->order->number;

        return "Requested by {$this->cancellation->requested_by} on {$order}.";
    }

    public function url(): string
    {
        return "/admin/cancellations/{$this->cancellation->id}";
    }

    public function tone(): string
    {
        return 'warning';
    }

    public function vendorId(): ?int
    {
        return $this->cancellation->order->items->first()?->vendor_id;
    }
}
