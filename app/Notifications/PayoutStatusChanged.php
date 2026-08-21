<?php

namespace App\Notifications;

use App\Models\VendorPayout;

/**
 * A payout moving on from `pending`. `PayoutRecorded` says money is coming;
 * this says whether it arrived.
 */
class PayoutStatusChanged extends AdminNotification
{
    public function __construct(private readonly VendorPayout $payout) {}

    public static function section(): string
    {
        return 'payouts';
    }

    public function title(): string
    {
        return match ($this->payout->status) {
            'paid' => "Payout {$this->payout->number} paid",
            'failed' => "Payout {$this->payout->number} failed",
            default => "Payout {$this->payout->number} is being processed",
        };
    }

    public function body(): string
    {
        $amount = number_format((float) $this->payout->net_amount, 2);

        return match ($this->payout->status) {
            'paid' => "₹{$amount} is on its way to your account.",
            'failed' => "₹{$amount} could not be sent. Check your payout details.",
            default => "₹{$amount} has been sent for processing.",
        };
    }

    public function url(): string
    {
        return '/admin/payouts';
    }

    public function tone(): string
    {
        return match ($this->payout->status) {
            'paid' => 'success',
            'failed' => 'danger',
            default => 'info',
        };
    }

    public function vendorId(): ?int
    {
        return $this->payout->vendor_id;
    }
}
