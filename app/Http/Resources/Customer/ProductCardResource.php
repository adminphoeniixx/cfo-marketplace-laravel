<?php

namespace App\Http\Resources\Customer;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A product as a grid tile: everything the card draws and nothing else.
 *
 * Listings return hundreds of these, so the heavy relations — variants,
 * description, reviews — deliberately stay on the detail resource.
 *
 * @mixin Product
 */
class ProductCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $price = (float) $this->price;
        $mrp = $this->compare_at_price !== null ? (float) $this->compare_at_price : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'brand' => $this->brand,
            'image' => $this->relationLoaded('images') ? $this->images->first()?->url : null,
            'price' => $price,
            'mrp' => $mrp,
            'discount_percent' => $mrp && $mrp > $price ? (int) round((1 - $price / $mrp) * 100) : 0,
            'rating' => (float) $this->rating,
            'ratings_count' => (int) ($this->reviews_count ?? 0),
            'in_stock' => $this->isInStock(),
            'vendor' => $this->whenLoaded('vendor', fn () => [
                'id' => $this->vendor->id,
                'name' => $this->vendor->name,
            ]),
            'is_wishlisted' => $this->when(
                isset($this->is_wishlisted),
                fn () => (bool) $this->is_wishlisted,
            ),
        ];
    }
}
