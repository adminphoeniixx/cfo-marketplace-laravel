<?php

namespace App\Http\Resources\Seller;

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
            'customer' => [
                // Enough to pack and deliver, and no more: no email, no
                // customer id, nothing that identifies them off this order.
                'name' => trim(($this->shipping_address['first_name'] ?? '').' '.($this->shipping_address['last_name'] ?? '')) ?: null,
                'phone' => $this->shipping_address['phone'] ?? $this->phone,
            ],
            'shipping_address' => $this->shipping_address,
            'customer_note' => $this->customer_note,
            'items' => OrderItemResource::collection($items),
            'totals' => [
                'items' => round((float) $mine->sum('total'), 2),
                'tax' => round((float) $mine->sum('tax_amount'), 2),
                'commission' => round((float) $mine->sum('commission_amount'), 2),
                'earning' => round((float) $mine->sum('vendor_earning'), 2),
            ],
            'placed_at' => $this->placed_at?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
