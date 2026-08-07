<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ScopesToStore;

    public function __invoke(Request $request): JsonResponse
    {
        $storeId = $this->storeId($request);
        $days = min(max($request->integer('days', 30), 1), 365);
        $since = now()->subDays($days);

        $window = OrderItem::query()
            ->where('order_items.vendor_id', $storeId)
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNot('orders.status', 'cancelled')
            ->where('orders.placed_at', '>=', $since);

        $totals = (clone $window)
            ->selectRaw('coalesce(sum(order_items.total), 0) as sales')
            ->selectRaw('coalesce(sum(order_items.vendor_earning), 0) as earning')
            ->selectRaw('coalesce(sum(order_items.quantity), 0) as units')
            ->selectRaw('count(distinct order_items.order_id) as orders')
            ->toBase()
            ->first();

        $topProducts = (clone $window)
            ->selectRaw('order_items.name, sum(order_items.quantity) as units, sum(order_items.total) as revenue')
            ->groupBy('order_items.name')
            ->orderByDesc('revenue')
            ->limit(5)
            ->toBase()
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'units' => (int) $row->units,
                'revenue' => round((float) $row->revenue, 2),
            ]);

        return response()->json([
            'range_days' => $days,
            'totals' => [
                'sales' => round((float) $totals->sales, 2),
                'earning' => round((float) $totals->earning, 2),
                'orders' => (int) $totals->orders,
                'units' => (int) $totals->units,
            ],
            'needs_attention' => [
                'unfulfilled_orders' => $this->ordersNeedingPacking($request)->count(),
                'pending_cancellations' => $this->storeCancellations($request)
                    ->where('status', 'pending')->count(),
                'pending_refunds' => $this->storeRefunds($request)
                    ->where('status', 'pending')->count(),
                'low_stock_products' => $this->storeProducts($request)
                    ->where('track_inventory', true)
                    ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
                    ->count(),
            ],
            'top_products' => $topProducts,
        ]);
    }
}
