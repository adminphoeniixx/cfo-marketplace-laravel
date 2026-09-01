<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\ReviewResource;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Ratings.
 *
 * Only people who bought the thing may rate it, and only once per product —
 * which is what makes the star average worth showing at all. The product's
 * cached `rating` is rewritten on every change, because that column is what
 * listing and search sort on.
 */
class ReviewController extends Controller
{
    use ScopesToCustomer;

    /** Published reviews for one product. Open to anyone. */
    public function index(Request $request, int $product): JsonResponse
    {
        $request->validate(['rating' => ['nullable', 'integer', 'min:1', 'max:5']]);

        $reviews = ProductReview::query()
            ->published()
            ->where('product_id', $product)
            ->when($request->integer('rating'), fn ($query, int $rating) => $query->where('rating', $rating))
            ->with('customer:id,first_name,last_name')
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return response()->json(
            $reviews->through(fn (ProductReview $review) => new ReviewResource($review))->toArray()
        );
    }

    /** What this shopper has written, and what is still waiting to be rated. */
    public function mine(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        $written = $customer->reviews()->with('product:id,name,slug')->orderByDesc('created_at')->get();

        // Delivered lines with no review yet — the "rate this" pile.
        $orders = Order::query()
            ->where('customer_id', $customer->id)
            ->where('status', 'completed')
            ->with('items')
            ->get();

        $pending = [];

        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $productId = $item->product_id;

                if (! $productId
                    || isset($pending[$productId])
                    || $written->contains('product_id', $productId)) {
                    continue;
                }

                $pending[$productId] = [
                    'order_number' => $order->number,
                    'product_id' => $productId,
                    'name' => $item->name,
                    'delivered_at' => $order->delivered_at?->toIso8601String(),
                ];
            }
        }

        return response()->json([
            'written' => ReviewResource::collection($written),
            'pending' => array_values($pending),
        ]);
    }

    public function store(Request $request, int $product): JsonResponse
    {
        $customer = $this->customer($request);

        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'title' => ['nullable', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:2000'],
        ]);

        $model = Product::findOrFail($product);

        // The order this rating is entitled to: delivered, theirs, and with
        // this product on it.
        $order = Order::query()
            ->where('customer_id', $customer->id)
            ->where('status', 'completed')
            ->whereHas('items', fn ($query) => $query->where('product_id', $model->id))
            ->latest('delivered_at')
            ->first();

        if (! $order) {
            throw ValidationException::withMessages([
                'rating' => 'Only people who have received this item can rate it.',
            ]);
        }

        $review = ProductReview::updateOrCreate(
            ['product_id' => $model->id, 'customer_id' => $customer->id],
            $data + ['order_id' => $order->id, 'is_verified' => true, 'status' => 'published'],
        );

        $model->refreshRating();

        return response()->json([
            'data' => new ReviewResource($review->load('customer:id,first_name,last_name')),
        ], 201);
    }

    public function destroy(Request $request, int $review): JsonResponse
    {
        $model = $this->customer($request)->reviews()->findOrFail($review);
        $product = $model->product;

        $model->delete();
        $product?->refreshRating();

        return response()->json(['deleted' => true]);
    }
}
