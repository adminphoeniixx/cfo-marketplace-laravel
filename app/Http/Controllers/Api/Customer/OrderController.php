<?php

namespace App\Http\Controllers\Api\Customer;

use App\Actions\Customer\PresentBasket;
use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\OrderItemResource;
use App\Http\Resources\Customer\OrderResource;
use App\Models\Cart;
use App\Models\DeliveryPartner;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Vendor;
use App\Services\Emoji;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    use ScopesToCustomer;

    public function __construct(protected PresentBasket $basket) {}

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
     * The courier's own scans are not something this marketplace holds, so the
     * milestones are built from the order's own timestamps and say plainly
     * which of them have happened. What is real — the carrier, its number, the
     * tracking link, the lines in the box — is real, and it is all here in one
     * response: the screen used to need this call *and* `GET /orders/{number}`
     * to draw itself.
     */
    public function track(Request $request, string $order): JsonResponse
    {
        $model = $this->findOwnedOrder($request, $order);
        $partner = $model->carrier ? DeliveryPartner::where('code', $model->carrier)
            ->orWhere('name', $model->carrier)->first() : null;

        $milestones = $this->milestones($model);
        // How many segments of the bar are filled. Never past the end, and
        // never behind a milestone the order has already reached.
        $progress = count(array_filter($milestones, fn (array $step) => $step['done']));

        $vendors = Vendor::whereIn('id', $model->items->pluck('vendor_id')->filter()->unique())
            ->get(['id', 'name', 'city'])
            ->keyBy('id');

        return response()->json([
            'data' => [
                'number' => $model->number,
                'status' => $model->status,
                'status_label' => $model->statusLabel(),
                'eta' => $model->etaLabel(),
                'carrier' => $partner ? [
                    'code' => $partner->code,
                    'name' => $partner->name,
                    'support_phone' => $partner->support_phone,
                    'tracking_url' => $partner->trackingUrlFor($model->tracking_number),
                ] : null,
                'tracking_number' => $model->tracking_number,
                'tracking_url' => DeliveryPartner::trackingUrlFrom($model->carrier, $model->tracking_number),
                'progress_step' => $progress,
                'progress_total' => count($milestones),
                'milestones' => $milestones,
                // The lines in the box, so the tracking screen does not have to
                // fetch the whole order to list what is coming.
                'items' => OrderItemResource::collection($model->items),
                'sellers' => $model->items->pluck('vendor_id')->filter()->unique()->values()
                    ->map(fn ($id) => [
                        'id' => (int) $id,
                        'name' => $vendors[$id]->name ?? 'Seller',
                        'city' => $vendors[$id]->city ?? null,
                    ])->all(),
                // The four keyed steps the first version of this endpoint
                // returned. Kept so nothing that reads them breaks.
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
     * The timeline the tracking screen draws.
     *
     * `done` is only ever true of something that actually happened — a step
     * with no timestamp behind it is drawn hollow, and `current` marks the one
     * the parcel is sitting on. A cancelled order stops where it stopped
     * rather than pretending the rest is still coming.
     *
     * @return list<array<string, mixed>>
     */
    protected function milestones(Order $model): array
    {
        $sellers = $model->items->pluck('vendor_id')->filter()->unique();
        $vendor = $sellers->count() === 1
            // Cast, because `find()` on a mixed key could as well be given a
            // list and hand back a collection.
            ? Vendor::select(['id', 'name', 'city'])->find((int) $sellers->first())
            : null;

        $from = $vendor
            ? trim($vendor->name.($vendor->city ? ', '.$vendor->city : ''))
            : $sellers->count().' sellers on this order';

        $steps = [[
            'key' => 'placed',
            'title' => 'Order placed',
            'subtitle' => $model->number.' · '.$model->items->sum('quantity').' item(s)',
            'at' => $model->placed_at?->toIso8601String(),
            'done' => $model->placed_at !== null,
        ]];

        if ($model->status === 'cancelled') {
            $steps[] = [
                'key' => 'cancelled',
                'title' => 'Order cancelled',
                'subtitle' => 'Anything already paid is refunded to the original method',
                'at' => $model->cancelled_at?->toIso8601String(),
                'done' => true,
            ];

            return $this->markCurrent($steps);
        }

        $steps[] = [
            'key' => 'payment',
            'title' => $model->isPayOnDelivery() ? 'Pay on delivery' : 'Payment confirmed',
            'subtitle' => $model->isPayOnDelivery()
                ? 'Collected by the courier at the door'
                : trim((string) $model->payment_method).($model->transaction_id ? ' · '.$model->transaction_id : ''),
            'at' => $model->paid_at?->toIso8601String(),
            'done' => $model->isPayOnDelivery() ? $model->placed_at !== null : $model->payment_status === 'paid',
        ];

        $steps[] = [
            'key' => 'picked_up',
            'title' => 'Picked up from the seller',
            'subtitle' => $from,
            'at' => $model->shipped_at?->toIso8601String(),
            'done' => $model->shipped_at !== null,
        ];

        $steps[] = [
            'key' => 'out_for_delivery',
            'title' => 'Out for delivery',
            'subtitle' => 'Assigned to a rider near you',
            // The courier does not report this to the marketplace yet, so it is
            // shown as reached only once the parcel actually arrived.
            'at' => null,
            'done' => $model->delivered_at !== null,
        ];

        $steps[] = [
            'key' => 'delivered',
            'title' => 'Delivered',
            'subtitle' => $model->delivered_at
                ? 'Signature or OTP taken at the door'
                : (string) $model->etaLabel(),
            'at' => $model->delivered_at?->toIso8601String(),
            'done' => $model->delivered_at !== null,
        ];

        return $this->markCurrent($steps);
    }

    /**
     * The first step that has not happened is where the parcel is now; once
     * everything is done, the last one is.
     *
     * @param  list<array<string, mixed>>  $steps
     * @return list<array<string, mixed>>
     */
    protected function markCurrent(array $steps): array
    {
        $current = null;

        foreach ($steps as $index => $step) {
            if (! $step['done']) {
                $current = $index;
                break;
            }
        }

        $current ??= count($steps) - 1;

        return array_map(
            fn (array $step, int $index) => [...$step, 'current' => $index === $current],
            $steps,
            array_keys($steps),
        );
    }

    /**
     * Put an old order's lines back in the basket.
     *
     * Partial success is the normal case, not an error: a basket bought months
     * ago will have something in it that is delisted, sold out, or from a
     * seller who has stopped trading. Every line says what happened to it and
     * why, and the whole basket comes back with them — the app used to be told
     * only "two added, one skipped" and had to fetch the cart again to find
     * out what that meant for the total.
     */
    public function reorder(Request $request, string $order): JsonResponse
    {
        $model = $this->findOwnedOrder($request, $order);
        $model->load(['items.product.variants', 'items.product.vendor:id,name,status']);
        $cart = $this->cartFor($request);

        $added = [];
        $skipped = [];

        foreach ($model->items as $item) {
            $refusal = $this->refusalFor($item);

            if ($refusal !== null) {
                $skipped[] = [
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'name' => $item->name,
                    'emoji' => Emoji::forProduct($item->name),
                    'reason' => $refusal,
                ];

                continue;
            }

            $added[] = $this->restore($cart, $item);
        }

        return response()->json([
            'added' => $added,
            'skipped' => $skipped,
            // The basket as `GET /cart` would return it, so the badge and the
            // totals are right the moment this call comes back.
            'cart' => $this->basket->handle($cart->fresh() ?? $cart),
        ]);
    }

    /**
     * Why this line cannot go back in the basket, or null when it can.
     *
     * The order matters: the most specific reason is the one worth showing, so
     * "the seller has paused" beats "out of stock" for a store that has closed
     * with stock still on the shelf.
     */
    protected function refusalFor(OrderItem $item): ?string
    {
        $product = $item->product;

        // A deleted product resolves to null through the relation's own
        // soft-delete scope, so both spellings of "gone" land here.
        if (! $product || $product->status !== 'active') {
            return 'No longer sold';
        }

        if ($product->vendor && $product->vendor->status !== 'approved') {
            return 'This seller is not trading right now';
        }

        if ($item->product_variant_id) {
            $variant = $product->variants->firstWhere('id', $item->product_variant_id);

            if (! $variant || ! $variant->is_active) {
                return 'That option is no longer available';
            }
        }

        return $product->isInStock() ? null : 'Out of stock';
    }

    /**
     * Put one line back, keeping its variant and as much of its quantity as
     * the shelf allows.
     *
     * @return array<string, mixed>
     */
    protected function restore(Cart $cart, OrderItem $item): array
    {
        $wanted = max((int) $item->quantity, 1);

        $line = $cart->items()
            ->where('product_id', $item->product_id)
            ->where('product_variant_id', $item->product_variant_id)
            ->first();

        if ($line) {
            $line->update([
                'quantity' => min($line->quantity + $wanted, 10),
                'saved_for_later' => false,
            ]);
        } else {
            $line = $cart->items()->create([
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'quantity' => min($wanted, 10),
            ]);
        }

        // What the shelf will actually give, which is what the line is clamped
        // to. `availableStock()` answers PHP_INT_MAX for anything untracked, so
        // a product without inventory is never trimmed.
        $line->refresh();
        $granted = max(min((int) $line->quantity, $line->availableStock(), 10), 1);

        if ($granted !== (int) $line->quantity) {
            $line->update(['quantity' => $granted]);
        }

        $short = $granted < $wanted;

        return [
            'cart_item_id' => $line->id,
            'product_id' => $item->product_id,
            'product_variant_id' => $item->product_variant_id,
            'name' => $item->name,
            'emoji' => Emoji::forProduct($item->name),
            'requested_quantity' => $wanted,
            'quantity' => $granted,
            'status' => $short ? 'partial' : 'added',
            'warning' => $short ? "Only {$granted} of {$wanted} could be added." : null,
        ];
    }
}
