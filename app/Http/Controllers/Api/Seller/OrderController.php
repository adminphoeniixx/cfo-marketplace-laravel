<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Http\Resources\Seller\OrderResource;
use App\Models\Order;
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
            ->when($request->string('search')->toString(), fn ($query, $search) => $query
                ->where('number', 'like', "%{$search}%"))
            ->when($request->string('status')->toString(), fn ($query, $status) => $query
                ->where('status', $status))
            ->when($request->string('fulfillment_status')->toString(), fn ($query, $status) => $query
                ->where('fulfillment_status', $status))
            ->when($request->date('from'), fn ($query, $from) => $query->where('placed_at', '>=', $from))
            ->when($request->date('to'), fn ($query, $to) => $query->where('placed_at', '<=', $to))
            ->latest('placed_at')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return OrderResource::collection($orders);
    }

    public function show(Request $request, int $order): OrderResource
    {
        return new OrderResource($this->findOwnedOrder($request, $order));
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
            'unfulfilled' => (clone $this->storeOrders($request))
                ->whereIn('fulfillment_status', ['unfulfilled', 'partially_fulfilled'])
                ->count(),
        ]);
    }
}
