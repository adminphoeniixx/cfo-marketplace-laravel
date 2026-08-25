<?php

namespace App\Http\Controllers\Api\Seller;

use App\Models\Cancellation;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Product;
use App\Models\Refund;
use App\Models\VendorPayout;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The single place the seller API decides "whose data is this".
 *
 * Every list starts from one of these builders and every single-record lookup
 * goes through `findOwned*`, so a row belonging to another store is a 404
 * rather than a leak. Nothing here ever reads an id out of the request body —
 * the store always comes from the token.
 */
trait ScopesToStore
{
    protected function storeId(Request $request): int
    {
        return (int) $request->user()->vendor_id;
    }

    /**
     * @return Builder<Product>
     */
    protected function storeProducts(Request $request): Builder
    {
        return Product::query()->where('vendor_id', $this->storeId($request));
    }

    /**
     * Orders holding at least one line from this store. The `items` relation
     * is constrained too, so a multi-vendor basket only ever shows our lines.
     *
     * @return Builder<Order>
     */
    protected function storeOrders(Request $request): Builder
    {
        $storeId = $this->storeId($request);

        return Order::query()
            ->whereHas('items', fn ($query) => $query->where('vendor_id', $storeId))
            ->with(['items' => fn ($query) => $query->where('vendor_id', $storeId)]);
    }

    /**
     * Orders this store still has packing left to do on.
     *
     * Deliberately not the order's own `fulfillment_status`: on a basket shared
     * with another seller that stays `partially_fulfilled` until *they* ship,
     * which would nag this seller about an order they have already finished.
     * What matters is whether any of **our** lines has quantity outstanding.
     *
     * @return Builder<Order>
     */
    protected function ordersNeedingPacking(Request $request): Builder
    {
        $storeId = $this->storeId($request);

        return Order::query()
            ->whereNot('status', 'cancelled')
            ->whereHas('items', fn ($query) => $query
                ->where('vendor_id', $storeId)
                ->whereRaw('order_items.quantity_fulfilled < order_items.quantity - order_items.quantity_cancelled'));
    }

    /**
     * @return Builder<Cancellation>
     */
    protected function storeCancellations(Request $request): Builder
    {
        $storeId = $this->storeId($request);

        return Cancellation::query()
            ->whereHas('items.orderItem', fn ($query) => $query->where('vendor_id', $storeId))
            ->with([
                'order:id,number',
                'items' => fn ($query) => $query->whereHas(
                    'orderItem',
                    fn ($q) => $q->where('vendor_id', $storeId)
                ),
                'items.orderItem:id,name,sku',
            ]);
    }

    /**
     * @return Builder<Refund>
     */
    protected function storeRefunds(Request $request): Builder
    {
        $storeId = $this->storeId($request);

        return Refund::query()
            ->whereHas('items.orderItem', fn ($query) => $query->where('vendor_id', $storeId))
            ->with([
                'order:id,number',
                'items' => fn ($query) => $query->whereHas(
                    'orderItem',
                    fn ($q) => $q->where('vendor_id', $storeId)
                ),
                'items.orderItem:id,name,sku',
            ]);
    }

    /**
     * @return Builder<VendorPayout>
     */
    protected function storePayouts(Request $request): Builder
    {
        return VendorPayout::query()->where('vendor_id', $this->storeId($request));
    }

    /**
     * An order's timeline as this store may read it.
     *
     * Two things are held back. Events another seller wrote — recognised by
     * their `meta.vendor_id` — are dropped outright, because on a shared
     * basket they are none of this store's business. And the actor is
     * flattened to "store" or "marketplace" rather than a staff name: a seller
     * needs to know whether the marketplace moved something, not who.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function storeTimeline(Order $order, int $storeId): array
    {
        return $order->events
            ->filter(function (OrderEvent $event) use ($storeId) {
                $eventStore = $event->meta['vendor_id'] ?? null;

                return $eventStore === null || (int) $eventStore === $storeId;
            })
            ->sortByDesc('created_at')
            ->values()
            ->map(fn (OrderEvent $event) => [
                'id' => $event->id,
                'type' => $event->type,
                'title' => $event->title,
                'body' => $event->body,
                'by' => ($event->meta['vendor_id'] ?? null) !== null ? 'store' : 'marketplace',
                'created_at' => $event->created_at?->toIso8601String(),
            ])
            ->all();
    }

    protected function findOwned(Request $request, int $id): Product
    {
        return $this->storeProducts($request)->findOrFail($id);
    }

    protected function findOwnedOrder(Request $request, int $id): Order
    {
        return $this->storeOrders($request)->findOrFail($id);
    }

    /**
     * Page size, capped so one client cannot ask for the whole table.
     */
    protected function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 25), 1), 100);
    }
}
