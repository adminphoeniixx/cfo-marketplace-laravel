<?php

namespace App\Notifications;

use App\Models\VendorPayout;

class PayoutRecorded extends AdminNotification
{
    public function __construct(private readonly VendorPayout $payout) {}

    public static function section(): string
    {
        return 'payouts';
    }

    public function title(): string
    {
        return "Payout {$this->payout->number} raised";
    }

    public function body(): string
    {
        $amount = number_format((float) $this->payout->net_amount, 2);

        return "₹{$amount} for {$this->payout->vendor?->name}.";
    }

    public function url(): string
    {
        return '/admin/payouts';
    }

    public function vendorId(): ?int
    {
        return $this->payout->vendor_id;
    }
}
