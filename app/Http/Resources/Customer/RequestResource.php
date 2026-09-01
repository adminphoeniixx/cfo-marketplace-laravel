<?php

namespace App\Http\Resources\Customer;

use App\Models\Cancellation;
use App\Models\Refund;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A cancellation or a return, in one shape.
 *
 * The database keeps them apart — different tables, different reason lists,
 * different decisions — but to a shopper they are one queue on the orders
 * screen, so the API says `kind` and hands back the same keys either way.
 *
 * @mixin Cancellation|Refund
 */
class RequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $refund = $this->resource instanceof Refund ? $this->resource : null;
        $cancellation = $this->resource instanceof Cancellation ? $this->resource : null;
        $isRefund = $refund !== null;

        return [
            'number' => $this->number,
            'kind' => $isRefund ? 'refund' : 'cancellation',
            'status' => $this->status,
            'order_number' => $this->whenLoaded('order', fn () => $this->order->number),
            'reason' => $this->reason,
            'reason_label' => ($isRefund ? Refund::REASONS : Cancellation::REASONS)[$this->reason] ?? $this->reason,
            'note' => $this->note,
            'amount' => (float) $this->total_amount,
            'method' => $refund ? (Refund::METHODS[$refund->method] ?? $refund->method) : null,
            'refund_requested' => $isRefund ? true : (bool) $cancellation?->refund_requested,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'name' => $item->orderItem?->name,
                'sku' => $item->orderItem?->sku,
                'quantity' => (int) $item->quantity,
                'amount' => (float) $item->amount,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'processed_at' => $refund?->processed_at?->toIso8601String(),
        ];
    }
}
