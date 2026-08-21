<?php

namespace App\Notifications;

use App\Models\Refund;

/**
 * The outcome of a refund request, including the moment the money actually
 * moves — which changes what a seller is owed, so it must not be silent.
 */
class RefundDecided extends AdminNotification
{
    public function __construct(private readonly Refund $refund) {}

    public static function section(): string
    {
        return 'refunds';
    }

    public function title(): string
    {
        return match ($this->refund->status) {
            'approved' => "Refund {$this->refund->number} approved",
            'processed' => "Refund {$this->refund->number} paid out",
            default => "Refund {$this->refund->number} rejected",
        };
    }

    public function body(): string
    {
        $amount = number_format((float) $this->refund->total_amount, 2);

        return match ($this->refund->status) {
            'approved' => "₹{$amount} approved, waiting to be paid.",
            'processed' => "₹{$amount} has gone back to the customer.",
            default => $this->refund->review_note ?: 'The request was turned down.',
        };
    }

    public function url(): string
    {
        return "/admin/refunds/{$this->refund->id}";
    }

    public function tone(): string
    {
        return match ($this->refund->status) {
            'processed' => 'warning',
            'approved' => 'info',
            default => 'neutral',
        };
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
