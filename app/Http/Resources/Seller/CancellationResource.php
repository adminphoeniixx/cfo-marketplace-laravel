<?php

namespace App\Http\Resources\Seller;

use App\Models\Cancellation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Cancellation
 */
class CancellationResource extends JsonResource
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
            'reason' => $this->reason,
            'reason_label' => Cancellation::REASONS[$this->reason] ?? $this->reason,
            'note' => $this->note,
            'requested_by' => $this->requested_by,
            'refund_requested' => (bool) $this->refund_requested,
            'restock' => (bool) $this->restock,
            'total_amount' => (float) $this->total_amount,
            'order' => [
                'id' => $this->order_id,
                'number' => $this->whenLoaded('order', fn () => $this->order->number),
            ],
            // Only the lines belonging to this store — the query scopes them.
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'order_item_id' => $item->order_item_id,
                'name' => $item->orderItem?->name,
                'sku' => $item->orderItem?->sku,
                'quantity' => (int) $item->quantity,
                'amount' => (float) $item->amount,
            ])),
            'review_note' => $this->review_note,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
