<?php

namespace App\Http\Resources\Seller;

use App\Models\Refund;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Refund
 */
class RefundResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'type' => $this->type,
            'reason' => $this->reason,
            'note' => $this->note,
            'method' => $this->method,
            'amounts' => [
                'items' => (float) $this->items_amount,
                'shipping' => (float) $this->shipping_amount,
                'tax' => (float) $this->tax_amount,
                'adjustment' => (float) $this->adjustment_amount,
                'total' => (float) $this->total_amount,
            ],
            'order' => [
                'id' => $this->order_id,
                'number' => $this->whenLoaded('order', fn () => $this->order->number),
            ],
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'order_item_id' => $item->order_item_id,
                'name' => $item->orderItem?->name,
                'sku' => $item->orderItem?->sku,
                'quantity' => (int) $item->quantity,
                'amount' => (float) $item->amount,
            ])),
            'review_note' => $this->review_note,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'processed_at' => $this->processed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
