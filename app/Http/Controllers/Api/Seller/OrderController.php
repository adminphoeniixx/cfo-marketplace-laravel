<?php

namespace App\Http\Controllers\Api\Seller;

use App\Actions\CreateManualOrder;
use App\Http\Controllers\Controller;
use App\Http\Resources\Seller\OrderResource;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    use ScopesToStore;

    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = $this->storeOrders($request)
            // Every line on the basket, ours and anyone else's, so the
            // resource can flag a shared basket without a count per row.
            ->withCount('items')
            ->when($request->string('search')->toString(), fn ($query, $search) => $query
                ->where('number', 'like', "%{$search}%"))
            ->when($request->string('status')->toString(), fn ($query, $status) => $query
                ->where('status', $status))
            ->when($request->string('fulfillment_status')->toString(), fn ($query, $status) => $query
                ->where('fulfillment_status', $status))
            // "Still mine to pack" — see ordersNeedingPacking(). The raw
            // `fulfillment_status` filter above stays available for anyone who
            // wants the order's own state instead.
            ->when($request->boolean('needs_packing'), fn ($query) => $query
                ->whereIn('id', $this->ordersNeedingPacking($request)->select('orders.id')))
            ->when($request->date('from'), fn ($query, $from) => $query->where('placed_at', '>=', $from))
            ->when($request->date('to'), fn ($query, $to) => $query->where('placed_at', '<=', $to))
            ->latest('placed_at')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return OrderResource::collection($orders);
    }

    public function show(Request $request, int $order): OrderResource
    {
        $model = $this->findOwnedOrder($request, $order);

        // `items` is re-stated with its constraint on purpose: naming
        // `events` alone is fine, but any nested load of `items.*` would
        // re-load the relation unscoped and pull another seller's lines in.
        $model->load([
            'items' => fn ($query) => $query->where('vendor_id', $this->storeId($request)),
            'events',
        ])->loadCount('items');

        return (new OrderResource($model))
            ->withTimeline($this->storeTimeline($model, $this->storeId($request)));
    }

    /**
     * Raise an order by hand — a phone order, a repeat customer, a fix for
     * something that went wrong at checkout.
     *
     * The store comes from the token, so the shared action never sees a vendor
     * id from the payload. Everything else — pricing, tax, commission, stock,
     * notifications — is the same code path the panels use.
     */
    public function store(Request $request, CreateManualOrder $action): JsonResponse
    {
        $data = $request->validate(CreateManualOrder::rules());

        $order = $action->handle(
            $data,
            Vendor::findOrFail($this->storeId($request)),
            $request->user(),
            'seller-api',
        );

        return response()->json(
            ['data' => new OrderResource($this->findOwnedOrder($request, $order->id))],
            201,
        );
    }

    /**
     * People this store may raise an order for: only those who have already
     * bought from them. The marketplace's wider customer book is not theirs.
     */
    public function customers(Request $request): JsonResponse
    {
        $storeId = $this->storeId($request);

        $customers = Customer::query()
            ->where('status', 'active')
            ->whereHas('orders.items', fn ($items) => $items->where('vendor_id', $storeId))
            ->when($request->string('search')->toString(), fn ($query, $search) => $query->where(
                fn ($q) => $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
            ))
            ->orderBy('first_name')
            ->limit(100)
            ->get(['id', 'first_name', 'last_name', 'email', 'phone']);

        return response()->json([
            'data' => $customers->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => trim("{$customer->first_name} {$customer->last_name}"),
                'email' => $customer->email,
                'phone' => $customer->phone,
            ]),
        ]);
    }

    /**
     * What the manual order form needs: this store's sellable products, each
     * with its variants, price, stock and tax rate already worked out.
     */
    public function sellable(Request $request): JsonResponse
    {
        $products = CreateManualOrder::sellableProducts($this->storeId($request));

        return response()->json([
            'data' => $products->map(fn ($product) => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'price' => (float) $product->price,
                'stock_quantity' => (int) $product->stock_quantity,
                'track_inventory' => (bool) $product->track_inventory,
                'allow_backorder' => (bool) $product->allow_backorder,
                'tax_rate' => CreateManualOrder::taxRateFor($product),
                'variants' => $product->variants->map(fn ($variant) => [
                    'id' => $variant->id,
                    'name' => $variant->name,
                    'sku' => $variant->sku,
                    'price' => (float) $variant->price,
                    'stock_quantity' => (int) $variant->stock_quantity,
                ])->values()->all(),
            ])->values()->all(),
        ]);
    }

    /**
     * Mark this store's lines as packed and shipped.
     *
     * A seller can only move their own lines, and the order-level status is
     * recomputed from every line — including other vendors' — so a two-vendor
     * order does not read as fully shipped when only half of it is.
     */
    public function fulfill(Request $request, int $order): OrderResource
    {
        $model = $this->findOwnedOrder($request, $order);
        $storeId = $this->storeId($request);

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:0'],
            'tracking_number' => ['nullable', 'string', 'max:120'],
            'carrier' => ['nullable', 'string', 'max:80'],
        ]);

        DB::transaction(function () use ($model, $data, $storeId, $request) {
            foreach ($data['items'] as $row) {
                // Scoped find: passing another vendor's order_item id 404s.
                $item = $model->items()
                    ->where('vendor_id', $storeId)
                    ->findOrFail((int) $row['id']);

                $quantity = min($row['quantity'], $item->quantity - $item->quantity_cancelled);

                $item->update([
                    'quantity_fulfilled' => $quantity,
                    'fulfillment_status' => match (true) {
                        $quantity <= 0 => 'unfulfilled',
                        $quantity >= $item->quantity - $item->quantity_cancelled => 'fulfilled',
                        default => 'partially_fulfilled',
                    },
                ]);
            }

            // Deliberately unscoped: the whole order decides the order status.
            $all = $model->items()->get();
            $expected = $all->sum(fn ($item) => $item->quantity - $item->quantity_cancelled);
            $fulfilled = $all->sum('quantity_fulfilled');

            $model->update([
                'fulfillment_status' => match (true) {
                    $fulfilled <= 0 => 'unfulfilled',
                    $fulfilled >= $expected => 'fulfilled',
                    default => 'partially_fulfilled',
                },
                'tracking_number' => $data['tracking_number'] ?? $model->tracking_number,
                'carrier' => $data['carrier'] ?? $model->carrier,
                'shipped_at' => $model->shipped_at ?? now(),
                'status' => $model->status === 'processing' && $fulfilled >= $expected
                    ? 'shipped'
                    : $model->status,
            ]);

            $model->recordEvent(
                'fulfillment',
                'Fulfilment updated by the seller',
                $request->user()->vendor->name.' updated their items.',
                ['vendor_id' => $storeId, 'source' => 'seller-api'],
            );
        });

        return new OrderResource($this->findOwnedOrder($request, $order));
    }

    /**
     * Leave a note on the order. Visible to admin staff on the order timeline.
     */
    public function addNote(Request $request, int $order): JsonResponse
    {
        $model = $this->findOwnedOrder($request, $order);

        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
        ]);

        $model->recordEvent(
            'note',
            'Note from '.$request->user()->vendor->name,
            $data['note'],
            ['vendor_id' => $this->storeId($request), 'source' => 'seller-api'],
        );

        return response()->json(['message' => 'Note added.'], 201);
    }

    /**
     * Counts per status, for the tabs at the top of a seller's order list.
     */
    public function summary(Request $request): JsonResponse
    {
        $counts = $this->storeOrders($request)
            ->getQuery()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'statuses' => collect(Order::STATUSES)
                ->mapWithKeys(fn (string $status) => [$status => (int) ($counts[$status] ?? 0)]),
            // Counts what `?needs_packing=1` returns, so the chip and the list
            // it opens can never disagree.
            'unfulfilled' => $this->ordersNeedingPacking($request)->count(),
        ]);
    }
}
