<?php

namespace App\Notifications;

use App\Models\Vendor;

class VendorRegistered extends AdminNotification
{
    public function __construct(private readonly Vendor $vendor) {}

    public static function section(): string
    {
        return 'vendors';
    }

    public function title(): string
    {
        return "{$this->vendor->name} is awaiting approval";
    }

    public function body(): string
    {
        return $this->vendor->store_email
            ? "New seller signed up · {$this->vendor->store_email}"
            : 'A new seller is waiting to be approved.';
    }

    public function url(): string
    {
        return "/admin/vendors/{$this->vendor->id}";
    }

    public function tone(): string
    {
        return 'attention';
    }
}
