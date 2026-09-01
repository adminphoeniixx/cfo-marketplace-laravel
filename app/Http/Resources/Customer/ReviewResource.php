<?php

namespace App\Http\Resources\Customer;

use App\Models\Customer;
use App\Models\ProductReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductReview
 */
class ReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'rating' => (int) $this->rating,
            'title' => $this->title,
            'body' => $this->body,
            // First name and a last initial — never the full name.
            'author' => $this->authorName(),
            'is_verified' => (bool) $this->is_verified,
            'is_mine' => $request->user('sanctum') instanceof Customer
                && $request->user('sanctum')->id === $this->customer_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
