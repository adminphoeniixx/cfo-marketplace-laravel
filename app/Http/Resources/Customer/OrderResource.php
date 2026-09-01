<?php

namespace App\Http\Resources\Customer;

use App\Models\DeliveryPartner;
use App\Models\Order;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order as the person who placed it sees it.
 *
 * The mirror image of the seller's resource: there, the money is one store's
 * share and the lines are one store's lines. Here it is the whole basket, and
 * commission — which is between the marketplace and the seller — never appears.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $items = $this->relationLoaded('items') ? $this->items : collect();
        $vendors = Vendor::whereIn('id', $items->pluck('vendor_id')->filter()->unique())
            ->get(['id', 'name', 'city'])
            ->keyBy('id');

        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'payment_status' => $this->payment_status,
            'fulfillment_status' => $this->fulfillment_status,
            'payment_method' => $this->payment_method,
            'shipping_method' => $this->shipping_method,
            'carrier' => $this->carrier,
            'tracking_number' => $this->tracking_number,
            'tracking_url' => DeliveryPartner::trackingUrlFrom($this->carrier, $this->tracking_number),
            'coupon_code' => $this->coupon_code,
            'customer_note' => $this->customer_note,
            'shipping_address' => $this->shipping_address,
            'totals' => [
                'subtotal' => (float) $this->subtotal,
                'discount_total' => (float) $this->discount_total,
                'tax_total' => (float) $this->tax_total,
                'shipping_total' => (float) $this->shipping_total,
                'grand_total' => (float) $this->grand_total,
                'refunded_total' => (float) $this->refunded_total,
            ],
            'items' => OrderItemResource::collection($items),
            // Two sellers means two parcels; the app says so on the order page.
            'sellers' => $items->pluck('vendor_id')->filter()->unique()->values()
                ->map(fn ($id) => [
                    'id' => (int) $id,
                    'name' => $vendors[$id]->name ?? 'Seller',
                    'city' => $vendors[$id]->city ?? null,
                ])->all(),
            'can_cancel' => $this->canBeCancelledByCustomer(),
            'can_return' => $this->canBeReturnedByCustomer(),
            'placed_at' => $this->placed_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'timeline' => $this->when($this->relationLoaded('events'), fn () => $this->timeline()),
        ];
    }

    protected function statusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'Payment pending',
            'processing' => 'Being packed',
            'on_hold' => 'On hold',
            'shipped' => 'On the way',
            'completed' => 'Delivered',
            'cancelled' => 'Cancelled',
            'refunded' => 'Refunded',
            default => ucfirst($this->status),
        };
    }

    /**
     * The order's own history, in the shopper's words.
     *
     * Staff and seller notes are dropped: an event without a customer-facing
     * type is internal, and the person who bought the thing has no business
     * reading it.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function timeline(): array
    {
        return $this->events
            ->whereIn('type', ['status', 'payment', 'shipment', 'cancellation', 'refund'])
            ->sortBy('created_at')
            ->values()
            ->map(fn ($event) => [
                'type' => $event->type,
                'title' => $event->title,
                'at' => $event->created_at?->toIso8601String(),
            ])
            ->all();
    }
}
