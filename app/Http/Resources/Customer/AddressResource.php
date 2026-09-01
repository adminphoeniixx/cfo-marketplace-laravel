<?php

namespace App\Http\Resources\Customer;

use App\Models\CustomerAddress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerAddress
 */
class AddressResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'name' => trim("{$this->first_name} {$this->last_name}"),
            'address_line1' => $this->address_line1,
            'address_line2' => $this->address_line2,
            'city' => $this->city,
            'state' => $this->state,
            'postcode' => $this->postcode,
            'country' => $this->country,
            'phone' => $this->phone,
            'is_default_shipping' => (bool) $this->is_default_shipping,
            'is_default_billing' => (bool) $this->is_default_billing,
        ];
    }
}
