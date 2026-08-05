<?php

namespace App\Http\Controllers\Api\Seller;

use App\Models\Cancellation;
use App\Models\Order;
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
