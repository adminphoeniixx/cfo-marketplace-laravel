<?php

namespace App\Http\Resources\Seller;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
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
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            // The sections the marketplace currently allows sellers, so the
            // app can hide a screen rather than let the seller open it and
            // collect a 403. The API enforces the same list either way.
            'sections' => Roles::forRole($this->role),
            'store' => new StoreResource($this->whenLoaded('vendor')),
        ];
    }
}
