<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\CategoryResource;
use App\Http\Resources\Customer\ProductCardResource;
use App\Http\Resources\Customer\ProductResource;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Browsing, which is the only part of the shopper API that works signed out.
 *
 * Everything here reads `active` products only, so a draft or archived listing
 * is invisible however it is reached — by id, by slug, by search or by
 * category. The one thing a token changes is `is_wishlisted`.
 */
class CatalogController extends Controller
{
    use ScopesToCustomer;

    /**
     * The category tree, parents with their children.
     */
    public function categories(Request $request): JsonResponse
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->whereNull('parent_id')
            ->with(['children' => fn ($query) => $query->where('is_active', true)->orderBy('position')])
            ->withCount(['products' => fn ($query) => $query->where('status', 'active')])
            ->orderBy('position')
            ->get();

        return response()->json(['data' => CategoryResource::collection($categories)]);
    }

    /**
     * The listing screen: search, filters, sort, paginate.
     *
     * `category` matches the category and its children, because a shopper who
     * taps "Ethnic wear" means everything under it, not the handful of
     * products filed directly against the parent.
     */
    public function products(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer'],
            'vendor_id' => ['nullable', 'integer'],
            'brand' => ['nullable', 'string', 'max:120'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'min_rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'min_discount' => ['nullable', 'integer', 'min:0', 'max:100'],
            'in_stock' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['relevance', 'price_low', 'price_high', 'rating', 'discount', 'newest'])],
        ]);

        $query = $this->sellable()
            // `whereLike(..., caseSensitive: false)` rather than `like`: plain
            // `like` is case-sensitive on Postgres and not on SQLite, so a
            // lowercase search found nothing on the server while the tests
            // stayed green. The grammar picks the right operator per driver.
            ->when($data['q'] ?? null, fn (Builder $q, string $term) => $q->where(
                fn (Builder $inner) => $inner
                    ->whereLike('name', "%{$term}%", caseSensitive: false)
                    ->orWhereLike('brand', "%{$term}%", caseSensitive: false)
                    ->orWhereLike('short_description', "%{$term}%", caseSensitive: false)
            ))
            ->when($data['category_id'] ?? null, fn (Builder $q, int $id) => $q
                ->whereIn('category_id', $this->categoryFamily($id)))
            ->when($data['vendor_id'] ?? null, fn (Builder $q, int $id) => $q->where('vendor_id', $id))
            ->when($data['brand'] ?? null, fn (Builder $q, string $brand) => $q->where('brand', $brand))
            ->when($data['min_price'] ?? null, fn (Builder $q, $min) => $q->where('price', '>=', $min))
            ->when($data['max_price'] ?? null, fn (Builder $q, $max) => $q->where('price', '<=', $max))
            ->when($data['min_rating'] ?? null, fn (Builder $q, $rating) => $q->where('rating', '>=', $rating))
            ->when($data['min_discount'] ?? null, fn (Builder $q, int $off) => $q
                ->whereNotNull('compare_at_price')
                // `* 1.0` matters: without it SQLite divides two integers and
                // every discounted product looks like 100% off.
                ->whereRaw('(1 - (price * 1.0 / NULLIF(compare_at_price, 0))) * 100 >= ?', [$off]))
            ->when($data['in_stock'] ?? null, fn (Builder $q) => $q
                ->where(fn (Builder $inner) => $inner
                    ->where('track_inventory', false)
                    ->orWhere('allow_backorder', true)
                    ->orWhere('stock_quantity', '>', 0)));

        $this->applySort($query, $data['sort'] ?? 'relevance');

        $products = $query->paginate($this->perPage($request))->withQueryString();

        $this->markWishlisted($request, collect($products->items()));

        return response()->json(
            $products->through(fn (Product $product) => new ProductCardResource($product))->toArray()
        );
    }

    /**
     * The product page. Takes an id or a slug, because links carry slugs.
     */
    public function product(Request $request, string $product): JsonResponse
    {
        $model = $this->sellable()
            ->with([
                'images',
                'category:id,name,slug',
                'vendor:id,name,city,rating',
                'taxClass.rates',
                'variants' => fn ($query) => $query->where('is_active', true)->orderBy('position'),
                'variants.values.attribute:id,name',
                'variants.values.attributeValue:id,value',
            ])
            ->where(fn (Builder $query) => $query
                ->where('slug', $product)
                ->when(ctype_digit($product), fn (Builder $q) => $q->orWhere('id', (int) $product)))
            ->firstOrFail();

        // The histogram under the stars, in one query rather than five.
        //
        // A list of objects rather than a rating-keyed map: an int-keyed JSON
        // object is awkward for typed clients, Laravel's resource filter
        // re-indexes one anyway, and the app needs the empty stars too.
        $counts = ProductReview::query()
            ->published()
            ->where('product_id', $model->id)
            ->selectRaw('rating, count(*) as total')
            ->groupBy('rating')
            ->pluck('total', 'rating');

        $model->rating_breakdown = collect([5, 4, 3, 2, 1])
            ->map(fn (int $star) => ['rating' => $star, 'count' => (int) ($counts[$star] ?? 0)])
            ->all();

        $this->markWishlisted($request, collect([$model]));

        return response()->json(['data' => new ProductResource($model)]);
    }

    /**
     * A seller's storefront: who they are, and what they have listed.
     */
    public function seller(Request $request, int $vendor): JsonResponse
    {
        $store = Vendor::where('status', 'approved')->findOrFail($vendor);

        $products = $this->sellable()
            ->where('vendor_id', $store->id)
            ->paginate($this->perPage($request))
            ->withQueryString();

        $this->markWishlisted($request, collect($products->items()));

        return response()->json([
            'data' => [
                'id' => $store->id,
                'name' => $store->name,
                'city' => $store->city,
                'rating' => (float) $store->rating,
                'description' => $store->description,
                'products_count' => $this->sellable()->where('vendor_id', $store->id)->count(),
                'since' => $store->approved_at?->format('Y'),
            ],
            'products' => $products->through(fn (Product $p) => new ProductCardResource($p))->toArray(),
        ]);
    }

    /**
     * Everything the home screen draws, in one call: the rails are the whole
     * screen, and three round trips to paint one screen is three too many.
     */
    public function home(Request $request): JsonResponse
    {
        $deals = $this->sellable()
            ->whereNotNull('compare_at_price')
            ->whereColumn('compare_at_price', '>', 'price')
            ->orderByRaw('(1 - (price * 1.0 / NULLIF(compare_at_price, 0))) desc')
            ->limit(8)->get();

        $topRated = $this->sellable()->orderByDesc('rating')->limit(8)->get();
        $newest = $this->sellable()->orderByDesc('published_at')->limit(8)->get();

        $this->markWishlisted($request, $deals->concat($topRated)->concat($newest));

        return response()->json([
            'categories' => CategoryResource::collection(
                Category::where('is_active', true)->whereNull('parent_id')->orderBy('position')->get()
            ),
            'deals' => ProductCardResource::collection($deals),
            'top_rated' => ProductCardResource::collection($topRated),
            'new_arrivals' => ProductCardResource::collection($newest),
            'sellers' => Vendor::where('status', 'approved')
                ->orderByDesc('rating')->limit(6)
                ->get(['id', 'name', 'city', 'rating']),
        ]);
    }

    /**
     * Type-ahead: a few product names and the categories they sit in.
     */
    public function suggestions(Request $request): JsonResponse
    {
        $term = $request->string('q')->toString();

        if (mb_strlen($term) < 2) {
            return response()->json(['products' => [], 'categories' => []]);
        }

        return response()->json([
            'products' => $this->sellable()
                ->where(fn (Builder $query) => $query
                    ->whereLike('name', "%{$term}%", caseSensitive: false)
                    ->orWhereLike('brand', "%{$term}%", caseSensitive: false))
                ->orderByDesc('rating')
                ->limit(6)
                ->get()
                ->map(fn (Product $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'slug' => $p->slug,
                    'brand' => $p->brand,
                    'price' => (float) $p->price,
                    'image' => $p->images->first()?->url,
                ]),
            'categories' => Category::where('is_active', true)
                ->whereLike('name', "%{$term}%", caseSensitive: false)
                ->limit(4)
                ->get(['id', 'name', 'slug']),
        ]);
    }

    /**
     * The one definition of "a shopper may see this".
     *
     * @return Builder<Product>
     */
    protected function sellable(): Builder
    {
        return Product::query()
            ->where('status', 'active')
            ->where(fn (Builder $query) => $query
                ->whereNull('published_at')
                ->orWhere('published_at', '<=', now()))
            ->with(['images', 'vendor:id,name'])
            ->withCount(['reviews' => fn ($query) => $query->where('status', 'published')]);
    }

    /**
     * @return array<int, int>
     */
    protected function categoryFamily(int $categoryId): array
    {
        return Category::where('id', $categoryId)
            ->orWhere('parent_id', $categoryId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  Builder<Product>  $query
     */
    protected function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'price_low' => $query->orderBy('price'),
            'price_high' => $query->orderByDesc('price'),
            'rating' => $query->orderByDesc('rating'),
            'newest' => $query->orderByDesc('published_at')->orderByDesc('id'),
            'discount' => $query->orderByRaw('(1 - (price * 1.0 / NULLIF(compare_at_price, price))) desc'),
            // "Relevance" with no search behind it is what sells: featured
            // first, then whatever people are actually rating well.
            default => $query->orderByDesc('is_featured')->orderByDesc('rating')->orderByDesc('id'),
        };
    }

    /**
     * Stamp `is_wishlisted` on a page of products for a signed-in shopper.
     *
     * One query for the whole page, and nothing at all when nobody is signed
     * in — the field is simply absent then, rather than a misleading `false`.
     *
     * @param  Collection<int, Product>  $products
     */
    protected function markWishlisted(Request $request, $products): void
    {
        // `user()` alone reads the session guard, which a token request has
        // none of. Asking the sanctum guard directly is what makes these
        // routes optionally authenticated rather than signed-out-only.
        $customer = $request->user('sanctum');

        if (! $customer instanceof Customer || $products->isEmpty()) {
            return;
        }

        $wishlisted = $customer->wishlistItems()
            ->whereIn('product_id', $products->pluck('id'))
            ->pluck('product_id')
            ->all();

        $products->each(fn (Product $product) => $product->is_wishlisted = in_array($product->id, $wishlisted, true));
    }
}
