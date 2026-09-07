<?php

namespace App\Http\Resources\Seller;

use App\Models\DeliveryPartner;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order as the seller is allowed to see it.
 *
 * Only this store's lines are ever included, and the money shown is this
 * store's share — never the buyer's basket total, which may span vendors.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * The order's timeline, already filtered to what this store may read.
     *
     * Carried on the resource rather than smuggled onto the model as a fake
     * attribute, so it is obvious this is a view concern and not a column.
     *
     * @var array<int, array<string, mixed>>|null
     */
    public ?array $timeline = null;

    /**
     * @param  array<int, array<string, mixed>>  $timeline
     */
    public function withTimeline(array $timeline): static
    {
        $this->timeline = $timeline;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $items = $this->whenLoaded('items', fn () => $this->items);
        $mine = $this->relationLoaded('items') ? $this->items : collect();

        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'fulfillment_status' => $this->fulfillment_status,
            'payment_method' => $this->payment_method,
            'shipping_method' => $this->shipping_method,
            'carrier' => $this->carrier,
            'tracking_number' => $this->tracking_number,
            // Built from the courier's own URL template, so the app can offer
            // a "track this" link without knowing anything about couriers.
            'tracking_url' => DeliveryPartner::trackingUrlFrom($this->carrier, $this->tracking_number),
            /*
            | The courier's own account of the parcel, which is not the
            | order's status and does not move with it. A seller needs this
            | one: `returning` and `undelivered` are the two states where
            | waiting achieves nothing and somebody has to act.
            */
            'shipment_status' => $this->shipment_status,
            'delivery_attempts' => (int) $this->delivery_attempts,
            'pickup_scheduled_at' => $this->pickup_scheduled_at?->toIso8601String(),
            'customer' => [
                // Enough to pack and deliver, and no more: no email, no
                // customer id, nothing that identifies them off this order.
                'name' => trim(($this->shipping_address['first_name'] ?? '').' '.($this->shipping_address['last_name'] ?? '')) ?: null,
                'phone' => $this->shipping_address['phone'] ?? $this->phone,
            ],
            'shipping_address' => $this->shipping_address,
            'customer_note' => $this->customer_note,
            'items' => OrderItemResource::collection($items),
            // Units still ours to pack. Deliberately not derived from
            // `fulfillment_status`: on a basket shared with another seller
            // that stays partial until *they* ship, which would nag this
            // seller about an order they have already finished.
            'to_pack' => (int) $mine->sum(fn ($item) => max(
                0,
                $item->quantity - $item->quantity_cancelled - $item->quantity_fulfilled,
            )),
            // True when the buyer's basket also holds another seller's goods,
            // so the app can say why `totals` is not the order total.
            'shared_basket' => $this->when(
                $this->relationLoaded('items'),
                // `items_count` is the whole basket — the controllers load it
                // alongside our own lines. Falling back to a query keeps any
                // other caller correct rather than quietly wrong.
                fn () => ($this->items_count ?? $this->items()->count()) > $mine->count(),
            ),
            // Filled by `withTimeline()` on the detail endpoint only; list
            // endpoints leave it out rather than loading events per row.
            'timeline' => $this->when(
                $this->timeline !== null,
                fn () => $this->timeline,
            ),
            'totals' => [
                'items' => round((float) $mine->sum('total'), 2),
                'tax' => round((float) $mine->sum('tax_amount'), 2),
                'commission' => round((float) $mine->sum('commission_amount'), 2),
                'earning' => round((float) $mine->sum('vendor_earning'), 2),
            ],
            'placed_at' => $this->placed_at?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'returned_at' => $this->returned_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
