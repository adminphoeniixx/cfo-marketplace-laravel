<?php

namespace App\Http\Resources\Seller;

use App\Models\Vendor;
use App\Services\BunnyCdn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Vendor
 */
class StoreResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status,
            'can_trade' => $this->status === 'approved',
            'email' => $this->store_email,
            'phone' => $this->phone,
            'logo_url' => BunnyCdn::url($this->logo_path),
            'description' => $this->description,
            'contact_name' => $this->contact_name,
            'address' => [
                'line1' => $this->address_line1,
                'line2' => $this->address_line2,
                'city' => $this->city,
                'state' => $this->state,
                'postcode' => $this->postcode,
                'country' => $this->country,
            ],
            'gst_number' => $this->gst_number,
            // Read-only: the marketplace sets these, not the seller.
            'commission_rate' => (float) $this->commission_rate,
            'rating' => (float) $this->rating,
            'payout' => [
                'method' => $this->payout_method,
                'account_name' => $this->bank_account_name,
                'account_number' => $this->bank_account_number,
                'ifsc' => $this->bank_ifsc,
            ],
            'rejection_reason' => $this->when(
                $this->status === 'rejected',
                $this->rejection_reason
            ),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
