<?php

namespace App\Http\Resources\Seller;

use App\Models\OrderItem;
use App\Services\BunnyCdn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrderItem
 */
class OrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'name' => $this->name,
            'sku' => $this->sku,
            'image_url' => BunnyCdn::url($this->image_path),
            'options' => $this->options,
            'unit_price' => (float) $this->unit_price,
            'quantity' => (int) $this->quantity,
            'quantity_cancelled' => (int) $this->quantity_cancelled,
            'quantity_refunded' => (int) $this->quantity_refunded,
            'quantity_fulfilled' => (int) $this->quantity_fulfilled,
            'fulfillment_status' => $this->fulfillment_status,
            'tax_amount' => (float) $this->tax_amount,
            'total' => (float) $this->total,
            'commission_rate' => (float) $this->commission_rate,
            'commission_amount' => (float) $this->commission_amount,
            'earning' => (float) $this->vendor_earning,
        ];
    }
}
