<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\CategoryResource;
use App\Http\Resources\Customer\ProductCardResource;
use App\Http\Resources\Customer\ProductResource;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\Vendor;
use App\Services\BunnyCdn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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
     * Everything the listing and the filter sheet both accept, in one place —
     * so a filter the app can send is a filter the counts are worked out for.
     *
     * @var array<string, array<int, mixed>>
     */
    public const FILTER_RULES = [
        'q' => ['nullable', 'string', 'max:120'],
        'category_id' => ['nullable', 'integer'],
        'vendor_id' => ['nullable', 'integer'],
        'brand' => ['nullable', 'string', 'max:120'],
        'min_price' => ['nullable', 'numeric', 'min:0'],
        'max_price' => ['nullable', 'numeric', 'min:0'],
        'min_rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
        'min_discount' => ['nullable', 'integer', 'min:0', 'max:100'],
        'in_stock' => ['nullable', 'boolean'],
        'assured' => ['nullable', 'boolean'],
        'cod' => ['nullable', 'boolean'],
        'sort' => ['nullable', 'in:relevance,price_low,price_high,rating,discount,newest'],
    ];

    /**
     * The bands the filter sheet offers. Rupees, because this marketplace is
     * priced in them; a currency switch would make these a setting.
     *
     * @var list<array{label: string, min: int|null, max: int|null}>
     */
    private const PRICE_BANDS = [
        ['label' => 'Under ₹500', 'min' => null, 'max' => 500],
        ['label' => '₹500 – ₹1,000', 'min' => 500, 'max' => 1000],
        ['label' => '₹1,000 – ₹2,500', 'min' => 1000, 'max' => 2500],
        ['label' => '₹2,500 – ₹5,000', 'min' => 2500, 'max' => 5000],
        ['label' => 'Over ₹5,000', 'min' => 5000, 'max' => null],
    ];

    /**
     * @var array<string, string>
     */
    private const SORTS = [
        'relevance' => 'Relevance',
        'price_low' => 'Price: low to high',
        'price_high' => 'Price: high to low',
        'rating' => 'Customer rating',
        'discount' => 'Discount',
        'newest' => 'Newest first',
    ];

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
        $data = $request->validate(self::FILTER_RULES);

        $query = $this->applyFilters($this->sellable(), $data);

        $this->applySort($query, $data['sort'] ?? 'relevance');

        $products = $query->paginate($this->perPage($request))->withQueryString();

        $this->markWishlisted($request, collect($products->items()));

        return response()->json(
            $products->through(fn (Product $product) => new ProductCardResource($product))->toArray()
        );
    }

    /**
     * The filter sheet, and how many products each choice would leave.
     *
     * Sent rather than hardcoded, because the app's own chips were a guess at
     * the catalogue: a price band nothing falls into, a seller who has stopped
     * trading, a brand that was never stocked. Each facet is counted against
     * every filter *except its own*, which is what makes multi-select read
     * correctly — picking one seller must not empty the seller list.
     */
    public function filters(Request $request): JsonResponse
    {
        $data = $request->validate(self::FILTER_RULES);

        // The catalogue as it stands under everything but the named dimension.
        $without = fn (string ...$except) => $this->applyFilters($this->sellable(), $data, array_values($except));

        $prices = $without('min_price', 'max_price');
        $ratings = $without('min_rating');
        $discounts = $without('min_discount');

        // Counted first, then named in one query rather than one per row.
        $sellerCounts = $this->countsBy($without('vendor_id'), 'vendor_id');
        $sellerNames = Vendor::whereIn('id', $sellerCounts->keys())->pluck('name', 'id');

        return response()->json([
            'data' => [
                'total' => (clone $without())->count(),
                'price_ranges' => collect(self::PRICE_BANDS)
                    ->map(fn (array $band) => [
                        'label' => $band['label'],
                        'min_price' => $band['min'],
                        'max_price' => $band['max'],
                        'count' => (clone $prices)
                            ->when($band['min'] !== null, fn (Builder $q) => $q->where('price', '>=', $band['min']))
                            ->when($band['max'] !== null, fn (Builder $q) => $q->where('price', '<=', $band['max']))
                            ->count(),
                    ])->all(),
                'rating_ranges' => collect([4, 3, 2])
                    ->map(fn (int $stars) => [
                        'label' => $stars.' ★ & above',
                        'min_rating' => $stars,
                        'count' => (clone $ratings)->where('rating', '>=', $stars)->count(),
                    ])->all(),
                'discount_ranges' => collect([10, 30, 50, 70])
                    ->map(fn (int $off) => [
                        'label' => $off.'% or more',
                        'min_discount' => $off,
                        'count' => $this->discountedAtLeast(clone $discounts, $off)->count(),
                    ])->all(),
                'sellers' => $sellerCounts
                    ->map(fn (int $count, int $id) => [
                        'id' => $id,
                        'name' => (string) ($sellerNames[$id] ?? 'Seller'),
                        'count' => $count,
                    ])->values()->all(),
                'brands' => $this->countsBy($without('brand'), 'brand')
                    ->map(fn (int $count, string $brand) => ['name' => $brand, 'count' => $count])
                    ->values()->all(),
                'boolean_filters' => [
                    [
                        'key' => 'assured',
                        'label' => 'Marketplace Assured',
                        'count' => (clone $without('assured'))->whereHas('vendor', fn ($q) => $q->where('is_assured', true))->count(),
                    ],
                    [
                        'key' => 'cod',
                        'label' => 'Cash on delivery',
                        'count' => PaymentMethod::payOnDeliveryIsOffered()
                            ? (clone $without('cod'))->whereHas('vendor', fn ($q) => $q->where('cod_available', true))->count()
                            : 0,
                    ],
                    [
                        'key' => 'in_stock',
                        'label' => 'In stock',
                        'count' => $this->inStockOnly(clone $without('in_stock'))->count(),
                    ],
                ],
                'sorts' => collect(self::SORTS)
                    ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                    ->values()->all(),
            ],
        ]);
    }

    /**
     * Every filter the listing understands, applied to a query.
     *
     * `$except` names the dimensions to leave off, which is what lets the
     * facet counts be worked out one at a time.
     *
     * @param  Builder<Product>  $query
     * @param  array<string, mixed>  $data
     * @param  list<string>  $except
     * @return Builder<Product>
     */
    protected function applyFilters(Builder $query, array $data, array $except = []): Builder
    {
        $value = fn (string $key) => in_array($key, $except, true) ? null : ($data[$key] ?? null);

        return $query
            // `whereLike(..., caseSensitive: false)` rather than `like`: plain
            // `like` is case-sensitive on Postgres and not on SQLite, so a
            // lowercase search found nothing on the server while the tests
            // stayed green. The grammar picks the right operator per driver.
            ->when($value('q'), fn (Builder $q, string $term) => $q->where(
                fn (Builder $inner) => $inner
                    ->whereLike('name', "%{$term}%", caseSensitive: false)
                    ->orWhereLike('brand', "%{$term}%", caseSensitive: false)
                    ->orWhereLike('short_description', "%{$term}%", caseSensitive: false)
            ))
            ->when($value('category_id'), fn (Builder $q, int $id) => $q
                ->whereIn('category_id', $this->categoryFamily($id)))
            ->when($value('vendor_id'), fn (Builder $q, int $id) => $q->where('vendor_id', $id))
            ->when($value('brand'), fn (Builder $q, string $brand) => $q->where('brand', $brand))
            ->when($value('min_price'), fn (Builder $q, $min) => $q->where('price', '>=', $min))
            ->when($value('max_price'), fn (Builder $q, $max) => $q->where('price', '<=', $max))
            ->when($value('min_rating'), fn (Builder $q, $rating) => $q->where('rating', '>=', $rating))
            ->when($value('min_discount'), fn (Builder $q, int $off) => $q
                ->whereNotNull('compare_at_price')
                // `* 1.0` matters: without it SQLite divides two integers and
                // every discounted product looks like 100% off.
                ->whereRaw('(1 - (price * 1.0 / NULLIF(compare_at_price, 0))) * 100 >= ?', [$off]))
            ->when($value('in_stock'), fn (Builder $q) => $this->inStockOnly($q))
            // The marketplace's own badge, carried by the store.
            ->when($value('assured'), fn (Builder $q) => $q
                ->whereHas('vendor', fn ($vendor) => $vendor->where('is_assured', true)))
            // Both halves have to be true: a store that takes cash cannot
            // offer it while the marketplace has the method switched off.
            ->when($value('cod'), fn (Builder $q) => PaymentMethod::payOnDeliveryIsOffered()
                ? $q->whereHas('vendor', fn ($vendor) => $vendor->where('cod_available', true))
                : $q->whereRaw('1 = 0'));
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    protected function inStockOnly(Builder $query): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->where('track_inventory', false)
            ->orWhere('allow_backorder', true)
            ->orWhere('stock_quantity', '>', 0));
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    protected function discountedAtLeast(Builder $query, int $percent): Builder
    {
        return $query->whereNotNull('compare_at_price')
            ->whereRaw('(1 - (price * 1.0 / NULLIF(compare_at_price, 0))) * 100 >= ?', [$percent]);
    }

    /**
     * How many products sit under each value of one column, biggest first.
     *
     * @param  Builder<Product>  $query
     * @return Collection<array-key, int>
     */
    protected function countsBy(Builder $query, string $column): Collection
    {
        return $query->getQuery()
            ->select($column)
            ->selectRaw('count(*) as total')
            ->whereNotNull($column)
            ->groupBy($column)
            ->orderByDesc('total')
            ->limit(30)
            ->pluck('total', $column)
            ->map(fn ($total) => (int) $total);
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
                'vendor:id,name,city,rating,is_assured,cod_available',
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
            // The carousel at the top. Empty until an admin adds one — which
            // is still better than the app carrying its own, where a sale
            // could not start without a release.
            'banners' => Banner::live()
                ->orderBy('position')
                ->orderBy('id')
                ->get()
                ->map(fn (Banner $banner) => [
                    'id' => $banner->id,
                    'title' => $banner->title,
                    'subtitle' => $banner->subtitle,
                    'image_url' => BunnyCdn::display($banner->image_path),
                    // Where tapping it goes: the app's own route name, and
                    // whatever that route needs.
                    'deeplink_route' => $banner->deeplink_route,
                    'deeplink_params' => (array) ($banner->deeplink_params ?? []),
                    'sort_order' => (int) $banner->position,
                    'active' => true,
                ])
                ->all(),
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
                    'emoji' => $p->emoji(),
                ]),
            'categories' => Category::where('is_active', true)
                ->whereLike('name', "%{$term}%", caseSensitive: false)
                ->limit(4)
                ->get(['id', 'name', 'slug', 'icon'])
                ->map(fn (Category $category) => [
                    ...$category->only(['id', 'name', 'slug']),
                    'icon' => $category->glyph(),
                ]),
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
            // `category` is here for the emoji fallback: one extra query per
            // page, against a tile that would otherwise be blank.
            ->with(['images', 'vendor:id,name,is_assured,cod_available', 'category:id,name,icon'])
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
