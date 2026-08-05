<?php

namespace App\Http\Resources\Seller;

use App\Models\VendorPayout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VendorPayout
 */
class PayoutResource extends JsonResource
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
            'method' => $this->method,
            'period' => [
                'start' => $this->period_start?->toDateString(),
                'end' => $this->period_end?->toDateString(),
            ],
            'orders_count' => (int) $this->orders_count,
            'amounts' => [
                'gross_sales' => (float) $this->gross_sales,
                'commission' => (float) $this->commission_amount,
                'refunded' => (float) $this->refunded_amount,
                'adjustment' => (float) $this->adjustment_amount,
                'net' => (float) $this->net_amount,
            ],
            'transaction_reference' => $this->transaction_reference,
            'note' => $this->note,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
