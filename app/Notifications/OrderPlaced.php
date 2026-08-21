<?php

namespace App\Notifications;

use App\Models\Order;

class OrderPlaced extends AdminNotification
{
    /**
     * @param  int|null  $storeId  whose copy this is; null is the staff copy,
     *                             which sees the whole basket
     */
    public function __construct(
        private readonly Order $order,
        private readonly ?int $storeId = null,
    ) {}

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
        // A seller is shown their own share, never the buyer's basket total —
        // the same rule `OrderResource` follows, and for the same reason: on a
        // shared basket the total is partly another seller's revenue.
        $amount = $this->storeId === null
            ? (float) $this->order->grand_total
            : (float) $this->order->items
                ->where('vendor_id', $this->storeId)
                ->sum('total');

        $total = number_format($amount, 2);
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
        return $this->storeId;
    }

    /**
     * Every store with a line on the basket — both sellers on a shared order
     * have a new order to pack.
     *
     * @return list<int>
     */
    public function vendorIds(): array
    {
        return array_values($this->order->items
            ->pluck('vendor_id')
            ->filter()
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->all());
    }
}
