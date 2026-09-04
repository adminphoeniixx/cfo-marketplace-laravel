<?php

namespace App\Http\Resources\Customer;

use App\Actions\CreateManualOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The product page.
 *
 * Variants are returned twice over on purpose: once as `options`, which is
 * what the size and colour pickers draw, and once as `variants`, which is what
 * the chosen combination resolves to. Building the options list here means the
 * app never has to work out which combinations exist.
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
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
            'sku' => $this->sku,
            'brand' => $this->brand,
            'type' => $this->type,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'price' => $price,
            'mrp' => $mrp,
            'discount_percent' => $mrp && $mrp > $price ? (int) round((1 - $price / $mrp) * 100) : 0,
            'tax_rate' => CreateManualOrder::taxRateFor($this->resource),
            'rating' => (float) $this->rating,
            'ratings_count' => (int) ($this->reviews_count ?? 0),
            'rating_breakdown' => $this->when(
                isset($this->rating_breakdown),
                fn () => $this->rating_breakdown,
            ),
            'in_stock' => $this->isInStock(),
            'stock_status' => $this->stock_status,
            'stock_quantity' => $this->track_inventory ? (int) $this->stock_quantity : null,
            'requires_shipping' => (bool) $this->requires_shipping,
            'tags' => $this->tags ?? [],
            'emoji' => $this->emoji(),
            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($image) => [
                'id' => $image->id,
                'url' => $image->url,
                'alt' => $image->alt ?? $this->name,
            ])->values()),
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
                'icon' => $this->category->glyph(),
            ] : null),
            'vendor' => $this->whenLoaded('vendor', fn () => $this->vendor ? [
                'id' => $this->vendor->id,
                'name' => $this->vendor->name,
                'city' => $this->vendor->city,
                'rating' => (float) ($this->vendor->rating ?? 0),
            ] : null),
            'specifications' => $this->specifications(),
            'options' => $this->options(),
            'variants' => $this->whenLoaded('variants', fn () => $this->variants
                ->where('is_active', true)
                ->map(fn (ProductVariant $variant) => [
                    'id' => $variant->id,
                    'name' => $variant->name,
                    'sku' => $variant->sku,
                    'price' => (float) $variant->price,
                    'mrp' => $variant->compare_at_price !== null ? (float) $variant->compare_at_price : $mrp,
                    'in_stock' => ! $this->track_inventory || $this->allow_backorder || $variant->stock_quantity > 0,
                    'stock_quantity' => (int) $variant->stock_quantity,
                    'values' => $variant->relationLoaded('values')
                        ? $variant->values->map(fn ($value) => [
                            'attribute_id' => $value->attribute_id,
                            'attribute' => $value->attribute->name,
                            'value_id' => $value->attribute_value_id,
                            'value' => $value->attributeValue->value,
                        ])->values()->all()
                        : [],
                ])->values()->all()),
            'is_wishlisted' => $this->when(
                isset($this->is_wishlisted),
                fn () => (bool) $this->is_wishlisted,
            ),
        ];
    }

    /**
     * The pickers: one entry per variant attribute, each value carrying
     * whether anything is left of it.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function options(): array
    {
        if (! $this->relationLoaded('variants')) {
            return [];
        }

        $options = [];

        foreach ($this->variants->where('is_active', true) as $variant) {
            if (! $variant->relationLoaded('values')) {
                continue;
            }

            foreach ($variant->values as $value) {
                $name = $value->attribute->name;
                $label = $value->attributeValue->value;

                $options[$name] ??= ['name' => $name, 'values' => []];
                $options[$name]['values'][$label] ??= ['value' => $label, 'stock' => 0];
                $options[$name]['values'][$label]['stock'] += (int) $variant->stock_quantity;
            }
        }

        return collect($options)
            ->map(fn (array $option) => [
                'name' => $option['name'],
                'values' => collect($option['values'])
                    ->map(fn (array $v) => $v + ['in_stock' => ! $this->track_inventory || $this->allow_backorder || $v['stock'] > 0])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * The spec table. Dimensions and weight are only worth showing when the
     * seller actually filled them in.
     *
     * @return array<string, string>
     */
    protected function specifications(): array
    {
        return array_filter([
            'Brand' => $this->brand,
            'SKU' => $this->sku,
            'Weight' => $this->weight > 0 ? $this->weight.' kg' : null,
            'Dimensions' => $this->length && $this->width && $this->height
                ? "{$this->length} × {$this->width} × {$this->height} cm"
                : null,
        ], fn ($value) => $value !== null && $value !== '');
    }
}
