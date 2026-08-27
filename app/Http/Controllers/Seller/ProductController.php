<?php

namespace App\Http\Controllers\Seller;

use App\Concerns\SyncsChildRecords;
use App\Http\Controllers\Api\Seller\ScopesToStore;
use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use App\Models\TaxClass;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The seller panel's catalog screens.
 *
 * Every query starts from `ScopesToStore` — the same trait the token API uses —
 * so "whose product is this" is answered in exactly one place for both clients.
 * Nothing here reads a vendor id out of the request: it always comes from the
 * signed-in user, which is what stops a seller filing a product under, or
 * opening a product belonging to, another store.
 */
class ProductController extends Controller
{
    use ScopesToStore, SyncsChildRecords;

    public function index(Request $request): Response
    {
        $products = $this->storeProducts($request)
            ->with(['category:id,name', 'images'])
            ->withCount('variants')
            ->when($request->string('search')->toString(), fn ($query, $search) => $query->where(
                fn ($q) => $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
            ))
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->when($request->filled('category'), fn ($query) => $query->where('category_id', $request->integer('category')))
            ->when($request->string('stock')->toString(), function ($query, $stock) {
                return match ($stock) {
                    'out' => $query->where('track_inventory', true)->where('stock_quantity', '<=', 0),
                    'low' => $query->where('track_inventory', true)
                        ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
                        ->where('stock_quantity', '>', 0),
                    default => $query,
                };
            })
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('seller/products/Index', [
            'products' => $products,
            'filters' => $request->only(['search', 'status', 'category', 'stock']),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'counts' => [
                'all' => $this->storeProducts($request)->count(),
                'active' => $this->storeProducts($request)->where('status', 'active')->count(),
                'draft' => $this->storeProducts($request)->where('status', 'draft')->count(),
                'archived' => $this->storeProducts($request)->where('status', 'archived')->count(),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('seller/products/Form', [
            'product' => null,
            ...$this->formOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $product = DB::transaction(function () use ($request, $data) {
            $product = Product::create([
                ...$this->productPayload($data),
                'vendor_id' => $this->storeId($request),
            ]);

            $this->syncRelations($product, $data);

            return $product;
        });

        return to_route('seller.products.edit', $product)
            ->with('success', "Product \"{$product->name}\" created.");
    }

    public function show(Request $request, int $product): RedirectResponse
    {
        return to_route('seller.products.edit', $this->findOwned($request, $product));
    }

    public function edit(Request $request, int $product): Response
    {
        $model = $this->findOwned($request, $product);
        $model->load(['images', 'variants.values', 'attributes', 'categories:id']);

        return Inertia::render('seller/products/Form', [
            'product' => $model,
            'stats' => [
                'units_sold' => (int) $model->orderItems()->sum('quantity'),
                'revenue' => (float) $model->orderItems()->sum('total'),
                'orders' => $model->orderItems()->distinct('order_id')->count('order_id'),
            ],
            ...$this->formOptions(),
        ]);
    }

    public function update(Request $request, int $product): RedirectResponse
    {
        $model = $this->findOwned($request, $product);
        $data = $this->validated($request, $model);

        DB::transaction(function () use ($model, $data) {
            $model->update($this->productPayload($data, $model));

            $this->syncRelations($model, $data);
        });

        return back()->with('success', "Product \"{$model->name}\" updated.");
    }

    public function destroy(Request $request, int $product): RedirectResponse
    {
        $this->findOwned($request, $product)->delete();

        return to_route('seller.products.index')->with('success', 'Product deleted.');
    }

    /**
     * Bulk status/delete from the index screen. Ids that are not this store's
     * are dropped rather than failing the batch, so a stale selection cannot
     * be used to probe whether another seller's product id exists.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['activate', 'draft', 'archive', 'delete'])],
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
        ]);

        $owned = $this->storeProducts($request)->whereIn('id', $data['ids'])->pluck('id');

        if ($owned->isEmpty()) {
            return back()->with('error', 'None of those products belong to your store.');
        }

        $query = Product::whereIn('id', $owned);

        match ($data['action']) {
            'activate' => $query->update(['status' => 'active', 'published_at' => now()]),
            'draft' => $query->update(['status' => 'draft']),
            'archive' => $query->update(['status' => 'archived']),
            'delete' => $query->delete(),
            default => null,
        };

        return back()->with('success', $owned->count().' product(s) updated.');
    }

    /**
     * Reference data the form needs. No vendor list — the store is fixed.
     *
     * @return array<string, mixed>
     */
    protected function formOptions(): array
    {
        return [
            'categories' => Category::with('parent:id,name')->orderBy('name')->get(['id', 'name', 'parent_id']),
            'taxClasses' => TaxClass::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'attributes' => Attribute::with('values:id,attribute_id,value,color_hex')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'type', 'is_variant']),
            'statuses' => Product::STATUSES,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?Product $product = null): array
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
    protected function productPayload(array $data, ?Product $product = null): array
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
    protected function syncRelations(Product $product, array $data): void
    {
        $product->categories()->sync($data['category_ids'] ?? []);
        $product->attributes()->sync($data['attribute_ids'] ?? []);

        $keptImages = [];

        foreach (array_values($data['images'] ?? []) as $position => $image) {
            $record = $this->syncChild(
                $product->images(),
                $image['id'] ?? null,
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
            $record = $this->syncChild(
                $product->variants(),
                $variant['id'] ?? null,
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

        // Keep the parent stock in sync with the sum of its variants.
        $product->update(['stock_quantity' => (int) $product->variants()->sum('stock_quantity')]);
    }
}
