<?php

namespace App\Http\Resources\Seller;

use App\Models\Product;
use App\Services\BunnyCdn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
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
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'type' => $this->type,
            'status' => $this->status,
            'is_featured' => (bool) $this->is_featured,
            'brand' => $this->brand,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'price' => (float) $this->price,
            'compare_at_price' => $this->compare_at_price !== null ? (float) $this->compare_at_price : null,
            'cost_price' => $this->cost_price !== null ? (float) $this->cost_price : null,
            'inventory' => [
                'tracked' => (bool) $this->track_inventory,
                'quantity' => (int) $this->stock_quantity,
                'low_stock_threshold' => (int) $this->low_stock_threshold,
                'allow_backorder' => (bool) $this->allow_backorder,
                'status' => $this->stock_status,
            ],
            'shipping' => [
                'required' => (bool) $this->requires_shipping,
                'weight' => (float) $this->weight,
                'length' => $this->length !== null ? (float) $this->length : null,
                'width' => $this->width !== null ? (float) $this->width : null,
                'height' => $this->height !== null ? (float) $this->height : null,
            ],
            'category_id' => $this->category_id,
            'tax_class_id' => $this->tax_class_id,
            'category_ids' => $this->whenLoaded('categories', fn () => $this->categories->pluck('id')),
            'attribute_ids' => $this->whenLoaded('attributes', fn () => $this->attributes->pluck('id')),
            'tags' => $this->tags ?? [],
            'seo' => [
                'title' => $this->seo_title,
                'description' => $this->seo_description,
            ],
            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($image) => [
                'id' => $image->id,
                'path' => $image->path,
                'url' => BunnyCdn::url($image->path),
                'alt' => $image->alt,
                'position' => $image->position,
            ])->all()),
            'variants' => $this->whenLoaded('variants', fn () => $this->variants->map(fn ($variant) => [
                'id' => $variant->id,
                'name' => $variant->name,
                'sku' => $variant->sku,
                'price' => (float) $variant->price,
                'compare_at_price' => $variant->compare_at_price !== null ? (float) $variant->compare_at_price : null,
                'stock_quantity' => (int) $variant->stock_quantity,
                'weight' => (float) $variant->weight,
                'is_active' => (bool) $variant->is_active,
                'values' => $variant->relationLoaded('values')
                    ? $variant->values->map(fn ($value) => [
                        'attribute_id' => $value->attribute_id,
                        'attribute_value_id' => $value->attribute_value_id,
                    ])->all()
                    : [],
            ])->all()),
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
