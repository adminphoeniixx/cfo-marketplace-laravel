<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\ProductCardResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WishlistController extends Controller
{
    use ScopesToCustomer;

    public function index(Request $request): JsonResponse
    {
        $productIds = $this->customer($request)->wishlistItems()
            ->orderByDesc('created_at')
            ->pluck('product_id');

        $products = Product::query()
            ->whereIn('id', $productIds)
            ->where('status', 'active')
            ->with(['images', 'vendor:id,name'])
            ->withCount(['reviews' => fn ($query) => $query->where('status', 'published')])
            ->get()
            // Keep the order the shopper saved them in, newest first.
            ->sortBy(fn (Product $product) => array_search($product->id, $productIds->all(), true))
            ->values();

        $products->each(fn (Product $product) => $product->is_wishlisted = true);

        return response()->json(['data' => ProductCardResource::collection($products)]);
    }

    /**
     * Idempotent on purpose — the app's heart is a toggle, and tapping it
     * twice on a flaky connection must not be an error.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
        ]);

        $this->customer($request)->wishlistItems()->firstOrCreate([
            'product_id' => $data['product_id'],
        ]);

        return response()->json(['wishlisted' => true], 201);
    }

    public function destroy(Request $request, int $product): JsonResponse
    {
        $this->customer($request)->wishlistItems()->where('product_id', $product)->delete();

        return response()->json(['wishlisted' => false]);
    }
}
