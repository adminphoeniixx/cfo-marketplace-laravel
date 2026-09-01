<?php

namespace App\Http\Resources\Customer;

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
            'vendor_id' => $this->vendor_id,
            'name' => $this->name,
            'sku' => $this->sku,
            'image' => BunnyCdn::display($this->image_path),
            'options' => $this->options,
            'unit_price' => (float) $this->unit_price,
            'quantity' => (int) $this->quantity,
            'quantity_cancelled' => (int) $this->quantity_cancelled,
            'quantity_refunded' => (int) $this->quantity_refunded,
            'quantity_fulfilled' => (int) $this->quantity_fulfilled,
            // What is still live on this line: what a cancellation or a return
            // may be raised against.
            'quantity_available' => (int) $this->quantity_available,
            'total' => (float) $this->total,
            'fulfillment_status' => $this->fulfillment_status,
        ];
    }
}
