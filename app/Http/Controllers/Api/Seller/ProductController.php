<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Http\Resources\Seller\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    use ScopesToStore;

    public function index(Request $request): AnonymousResourceCollection
    {
        $products = $this->storeProducts($request)
            ->with(['images', 'categories:id', 'attributes:id'])
            ->when($request->string('search')->toString(), fn ($query, $search) => $query->where(
                fn ($q) => $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
            ))
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->when($request->boolean('low_stock'), fn ($query) => $query
                ->where('track_inventory', true)
                ->whereColumn('stock_quantity', '<=', 'low_stock_threshold'))
            ->when($request->string('sort')->toString(), function ($query, $sort) {
                return match ($sort) {
                    'name' => $query->orderBy('name'),
                    'price' => $query->orderBy('price'),
                    'stock' => $query->orderBy('stock_quantity'),
                    'oldest' => $query->oldest('id'),
                    default => $query->latest('id'),
                };
            }, fn ($query) => $query->latest('id'))
            ->paginate($this->perPage($request))
            ->withQueryString();

        return ProductResource::collection($products);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $product = DB::transaction(function () use ($request, $data) {
            // The store is taken from the token, never from the payload — a
            // seller must not be able to file a product under another store.
            $product = Product::create([
                ...$this->payload($data),
                'vendor_id' => $this->storeId($request),
            ]);

            $this->syncRelations($product, $data);

            return $product;
        });

        return response()->json(
            ['data' => new ProductResource($this->loaded($product))],
            201,
        );
    }

    public function show(Request $request, int $product): ProductResource
    {
        return new ProductResource($this->loaded($this->findOwned($request, $product)));
    }

    public function update(Request $request, int $product): ProductResource
    {
        $model = $this->findOwned($request, $product);
        $data = $this->validated($request, $model);

        DB::transaction(function () use ($model, $data) {
            $model->update($this->payload($data, $model));

            $this->syncRelations($model, $data);
        });

        return new ProductResource($this->loaded($model->fresh()));
    }

    public function destroy(Request $request, int $product): JsonResponse
    {
        $this->findOwned($request, $product)->delete();

        return response()->json(['message' => 'Product deleted.']);
    }

    /**
     * Flip status without sending the whole product back.
     */
    public function updateStatus(Request $request, int $product): ProductResource
    {
        $model = $this->findOwned($request, $product);

        $data = $request->validate([
            'status' => ['required', Rule::in(Product::STATUSES)],
        ]);

        $model->update([
            'status' => $data['status'],
            'published_at' => $data['status'] === 'active'
                ? ($model->published_at ?? now())
                : $model->published_at,
        ]);

        return new ProductResource($this->loaded($model));
    }

    /**
     * Adjust stock without touching anything else — the common warehouse job.
     */
    public function updateStock(Request $request, int $product): ProductResource
    {
        $model = $this->findOwned($request, $product);

        $data = $request->validate([
            'stock_quantity' => ['required', 'integer', 'min:0', 'max:9999999'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($model->type === 'variable') {
            return $this->rejectVariableStock($model);
        }

        $model->update(array_filter([
            'stock_quantity' => $data['stock_quantity'],
            'low_stock_threshold' => $data['low_stock_threshold'] ?? null,
        ], fn ($value) => $value !== null));

        return new ProductResource($this->loaded($model));
    }

    private function rejectVariableStock(Product $product): ProductResource
    {
        abort(422, 'Stock for a variable product is the sum of its variants — update those instead.');
    }

    private function loaded(Product $product): Product
    {
        return $product->load(['images', 'variants.values', 'categories:id', 'attributes:id']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Product $product = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'slug' => ['nullable', 'string', 'max:220'],
            'sku' => ['nullable', 'string', 'max:80', Rule::unique('products', 'sku')->ignore($product)],
            'barcode' => ['nullable', 'string', 'max:80'],
            'type' => ['required', Rule::in(['simple', 'variable'])],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'tax_class_id' => ['nullable', 'integer', 'exists:tax_classes,id'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:20000'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'compare_at_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'cost_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'track_inventory' => ['boolean'],
            'stock_quantity' => ['required_if:track_inventory,true', 'nullable', 'integer', 'min:0'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'allow_backorder' => ['boolean'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'length' => ['nullable', 'numeric', 'min:0'],
            'width' => ['nullable', 'numeric', 'min:0'],
            'height' => ['nullable', 'numeric', 'min:0'],
            'requires_shipping' => ['boolean'],
            'status' => ['required', Rule::in(Product::STATUSES)],
            'brand' => ['nullable', 'string', 'max:120'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:40'],
            'seo_title' => ['nullable', 'string', 'max:180'],
            'seo_description' => ['nullable', 'string', 'max:400'],

            'category_ids' => ['array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],

            'images' => ['array', 'max:10'],
            'images.*.id' => ['nullable', 'integer'],
            'images.*.path' => ['required', 'string', 'max:500'],
            'images.*.alt' => ['nullable', 'string', 'max:180'],

            'attribute_ids' => ['array'],
            'attribute_ids.*' => ['integer', 'exists:attributes,id'],

            'variants' => ['array', 'max:100'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.name' => ['nullable', 'string', 'max:200'],
            'variants.*.sku' => ['nullable', 'string', 'max:80'],
            'variants.*.price' => ['required', 'numeric', 'min:0'],
            'variants.*.compare_at_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.stock_quantity' => ['required', 'integer', 'min:0'],
            'variants.*.weight' => ['nullable', 'numeric', 'min:0'],
            'variants.*.is_active' => ['boolean'],
            'variants.*.values' => ['array'],
            'variants.*.values.*.attribute_id' => ['required', 'integer', 'exists:attributes,id'],
            'variants.*.values.*.attribute_value_id' => ['required', 'integer', 'exists:attribute_values,id'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(array $data, ?Product $product = null): array
    {
        $payload = collect($data)
            ->except(['category_ids', 'images', 'attribute_ids', 'variants', 'slug'])
            ->all();

        $payload['slug'] = ! empty($data['slug'])
            ? Product::uniqueSlug($data['slug'], $product?->id)
            : ($product->slug ?? Product::uniqueSlug($data['name']));

        $payload['stock_quantity'] = $data['stock_quantity'] ?? 0;
        $payload['low_stock_threshold'] = $data['low_stock_threshold'] ?? 5;
        $payload['weight'] = $data['weight'] ?? 0;

        if ($data['status'] === 'active' && ! $product?->published_at) {
            $payload['published_at'] = now();
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncRelations(Product $product, array $data): void
    {
        $product->categories()->sync($data['category_ids'] ?? []);
        $product->attributes()->sync($data['attribute_ids'] ?? []);

        $keptImages = [];

        foreach (array_values($data['images'] ?? []) as $position => $image) {
            $record = $product->images()->updateOrCreate(
                ['id' => $image['id'] ?? null],
                ['path' => $image['path'], 'alt' => $image['alt'] ?? $product->name, 'position' => $position],
            );

            $keptImages[] = $record->id;
        }

        $product->images()->whereNotIn('id', $keptImages)->delete();

        if ($product->type !== 'variable') {
            $product->variants()->delete();

            return;
        }

        $keptVariants = [];

        foreach (array_values($data['variants'] ?? []) as $position => $variant) {
            $record = $product->variants()->updateOrCreate(
                ['id' => $variant['id'] ?? null],
                [
                    'name' => $variant['name'] ?? null,
                    'sku' => $variant['sku'] ?? null,
                    'price' => $variant['price'],
                    'compare_at_price' => $variant['compare_at_price'] ?? null,
                    'stock_quantity' => $variant['stock_quantity'],
                    'weight' => $variant['weight'] ?? 0,
                    'is_active' => $variant['is_active'] ?? true,
                    'position' => $position,
                ],
            );

            $record->values()->delete();

            foreach ($variant['values'] ?? [] as $value) {
                $record->values()->create([
                    'attribute_id' => $value['attribute_id'],
                    'attribute_value_id' => $value['attribute_value_id'],
                ]);
            }

            $keptVariants[] = $record->id;
        }

        $product->variants()->whereNotIn('id', $keptVariants)->delete();

        $product->update(['stock_quantity' => (int) $product->variants()->sum('stock_quantity')]);
    }
}
