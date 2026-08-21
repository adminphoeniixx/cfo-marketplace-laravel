<?php

namespace App\Notifications;

use App\Models\Vendor;

/**
 * The marketplace's answer to a seller's application, and to anything that
 * changes it afterwards.
 *
 * This is the one notification addressed to a store rather than to a section —
 * no entry in the role matrix means "news about yourself" — so it goes out
 * through `Notifier::toStore()` and its url points at the app's own store
 * screen rather than an admin path.
 */
class StoreStatusChanged extends AdminNotification
{
    public function __construct(private readonly Vendor $vendor) {}

    /**
     * Never used to pick an audience — `toStore()` does that — but the base
     * class needs one, and the panel groups by it.
     */
    public static function section(): string
    {
        return 'vendors';
    }

    public function title(): string
    {
        return match ($this->vendor->status) {
            'approved' => 'Your store is approved',
            'rejected' => 'Your store application was not accepted',
            'suspended' => 'Your store has been suspended',
            default => 'Your store is waiting for approval',
        };
    }

    public function body(): string
    {
        return match ($this->vendor->status) {
            'approved' => 'You can start listing products and taking orders.',
            // The reason is the whole point of the message — without it the
            // seller has nothing to act on.
            'rejected' => $this->vendor->rejection_reason
                ? "Reason: {$this->vendor->rejection_reason}"
                : 'Contact support if you think this is a mistake.',
            'suspended' => 'Your products have been hidden. Contact support to sort this out.',
            default => 'We will let you know as soon as it has been reviewed.',
        };
    }

    /** The seller's own store screen, not an admin page. */
    public function url(): string
    {
        return '/store';
    }

    public function tone(): string
    {
        return match ($this->vendor->status) {
            'approved' => 'success',
            'rejected', 'suspended' => 'danger',
            default => 'info',
        };
    }

    public function vendorId(): ?int
    {
        return $this->vendor->id;
    }
}
