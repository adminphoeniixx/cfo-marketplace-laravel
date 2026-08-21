<?php

namespace App\Notifications;

use App\Models\Cancellation;

/**
 * The outcome of a cancellation request. The seller has a Requests screen and
 * no other way to learn what was decided short of polling it.
 */
class CancellationDecided extends AdminNotification
{
    public function __construct(private readonly Cancellation $cancellation) {}

    public static function section(): string
    {
        return 'cancellations';
    }

    public function title(): string
    {
        $verb = $this->cancellation->status === 'approved' ? 'approved' : 'rejected';

        return "Cancellation {$this->cancellation->number} {$verb}";
    }

    public function body(): string
    {
        $order = $this->cancellation->order?->number;

        return $this->cancellation->review_note
            ?: ($this->cancellation->status === 'approved'
                ? "The items on {$order} have been cancelled."
                : "The request on {$order} was turned down.");
    }

    public function url(): string
    {
        return "/admin/cancellations/{$this->cancellation->id}";
    }

    public function tone(): string
    {
        return $this->cancellation->status === 'approved' ? 'success' : 'neutral';
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
        return array_values($this->cancellation->items
            ->map(fn ($line) => $line->orderItem?->vendor_id)
            ->filter()->unique()
            ->map(fn ($id) => (int) $id)->all());
    }
}
