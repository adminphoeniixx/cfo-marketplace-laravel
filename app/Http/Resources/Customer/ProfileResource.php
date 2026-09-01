<?php

namespace App\Http\Resources\Customer;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Customer
 */
class ProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'gender' => $this->gender,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'accepts_marketing' => (bool) $this->accepts_marketing,
            'email_verified' => (bool) $this->email_verified,
            'phone_verified' => $this->phone_verified_at !== null,
            'has_password' => $this->password !== null,
            'orders_count' => (int) $this->orders_count,
            'total_spent' => (float) $this->total_spent,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
