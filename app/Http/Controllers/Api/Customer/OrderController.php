<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\OrderResource;
use App\Models\DeliveryPartner;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    use ScopesToCustomer;

    /**
     * The orders screen, with the filters the app's chips map onto.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'filter' => ['nullable', Rule::in(['all', 'open', 'delivered', 'cancelled'])],
        ]);

        $orders = $this->customerOrders($request)
            ->with(['items'])
            ->when($request->string('filter')->toString(), fn ($query, string $filter) => match ($filter) {
                'open' => $query->whereIn('status', ['pending', 'processing', 'on_hold', 'shipped']),
                'delivered' => $query->where('status', 'completed'),
                'cancelled' => $query->whereIn('status', ['cancelled', 'refunded']),
                default => $query,
            })
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return response()->json(
            $orders->through(fn (Order $order) => new OrderResource($order))->toArray()
        );
    }

    public function show(Request $request, string $order): JsonResponse
    {
        return response()->json([
            'data' => new OrderResource($this->findOwnedOrder($request, $order)),
        ]);
    }

    /**
     * Where the parcel is.
     *
     * The courier's own milestones are not something this marketplace holds
     * yet, so the steps come from the order's own timestamps. The carrier and
     * its support number are real, which is what the screen actually needs.
     */
    public function track(Request $request, string $order): JsonResponse
    {
        $model = $this->findOwnedOrder($request, $order);
        $partner = $model->carrier ? DeliveryPartner::where('code', $model->carrier)->first() : null;

        return response()->json([
            'data' => [
                'number' => $model->number,
                'status' => $model->status,
                'carrier' => $partner ? [
                    'code' => $partner->code,
                    'name' => $partner->name,
                    'support_phone' => $partner->support_phone,
                ] : null,
                'tracking_number' => $model->tracking_number,
                'tracking_url' => DeliveryPartner::trackingUrlFrom($model->carrier, $model->tracking_number),
                'steps' => [
                    ['key' => 'placed', 'label' => 'Order placed', 'at' => $model->placed_at?->toIso8601String()],
                    ['key' => 'paid', 'label' => $model->payment_status === 'paid' ? 'Payment confirmed' : 'Pay on delivery', 'at' => $model->paid_at?->toIso8601String()],
                    ['key' => 'shipped', 'label' => 'Shipped', 'at' => $model->shipped_at?->toIso8601String()],
                    ['key' => 'delivered', 'label' => 'Delivered', 'at' => $model->delivered_at?->toIso8601String()],
                ],
            ],
        ]);
    }

    /**
     * Put an old order's lines back in the basket.
     *
     * Anything since delisted or sold out is skipped rather than failing the
     * whole call, and the response says what was left behind.
     */
    public function reorder(Request $request, string $order): JsonResponse
    {
        $model = $this->findOwnedOrder($request, $order);
        $cart = $this->cartFor($request);

        $added = 0;
        $skipped = [];

        foreach ($model->items as $item) {
            $product = $item->product;

            if (! $product || $product->status !== 'active' || ! $product->isInStock()) {
                $skipped[] = $item->name;

                continue;
            }

            $line = $cart->items()
                ->where('product_id', $product->id)
                ->where('product_variant_id', $item->product_variant_id)
                ->first();

            $line
                ? $line->update(['quantity' => min($line->quantity + $item->quantity, 10), 'saved_for_later' => false])
                : $cart->items()->create([
                    'product_id' => $product->id,
                    'product_variant_id' => $item->product_variant_id,
                    'quantity' => min((int) $item->quantity, 10),
                ]);

            $added++;
        }

        return response()->json(['added' => $added, 'skipped' => $skipped]);
    }
}
