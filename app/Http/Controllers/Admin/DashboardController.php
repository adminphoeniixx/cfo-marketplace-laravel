<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Cancellation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $range = (int) $request->integer('range', 30);
        $range = in_array($range, [7, 30, 90, 365], true) ? $range : 30;

        $start = Carbon::today()->subDays($range - 1);
        $previousStart = (clone $start)->subDays($range);

        $current = $this->totals($start, Carbon::now());
        $previous = $this->totals($previousStart, (clone $start)->subSecond());

        return Inertia::render('admin/Dashboard', [
            'range' => $range,
            'metrics' => [
                'revenue' => [
                    'value' => $current['revenue'],
                    'change' => $this->change($current['revenue'], $previous['revenue']),
                ],
                'orders' => [
                    'value' => $current['orders'],
                    'change' => $this->change($current['orders'], $previous['orders']),
                ],
                'average_order' => [
                    'value' => $current['orders'] > 0 ? round($current['revenue'] / $current['orders'], 2) : 0,
                    'change' => $this->change(
                        $current['orders'] > 0 ? $current['revenue'] / $current['orders'] : 0,
                        $previous['orders'] > 0 ? $previous['revenue'] / $previous['orders'] : 0,
                    ),
                ],
                'customers' => [
                    'value' => Customer::whereBetween('created_at', [$start, Carbon::now()])->count(),
                    'change' => $this->change(
                        Customer::whereBetween('created_at', [$start, Carbon::now()])->count(),
                        Customer::whereBetween('created_at', [$previousStart, (clone $start)->subSecond()])->count(),
                    ),
                ],
                'commission' => [
                    'value' => (float) Order::whereBetween('placed_at', [$start, Carbon::now()])
                        ->whereNotIn('status', ['cancelled'])->sum('commission_total'),
                    'change' => null,
                ],
                'refunded' => [
                    'value' => (float) Refund::where('status', 'processed')
                        ->whereBetween('processed_at', [$start, Carbon::now()])->sum('total_amount'),
                    'change' => null,
                ],
            ],
            'salesSeries' => $this->salesSeries($start, $range),
            'statusBreakdown' => Order::query()
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status'),
            'topProducts' => OrderItem::query()
                ->selectRaw('name, sum(quantity) as qty, sum(total) as revenue')
                ->whereHas('order', fn ($q) => $q->whereBetween('placed_at', [$start, Carbon::now()])->whereNot('status', 'cancelled'))
                ->groupBy('name')
                ->orderByDesc('revenue')
                ->limit(6)
                ->get(),
            'topVendors' => Vendor::query()
                ->select('vendors.id', 'vendors.name')
                ->selectRaw('coalesce(sum(order_items.total), 0) as revenue')
                ->selectRaw('count(distinct order_items.order_id) as orders_count')
                ->leftJoin('order_items', 'order_items.vendor_id', '=', 'vendors.id')
                ->leftJoin('orders', 'orders.id', '=', 'order_items.order_id')
                ->where(fn ($q) => $q->whereBetween('orders.placed_at', [$start, Carbon::now()])->orWhereNull('orders.id'))
                ->groupBy('vendors.id', 'vendors.name')
                ->orderByDesc('revenue')
                ->limit(6)
                ->get(),
            'recentOrders' => Order::with('customer:id,first_name,last_name')
                ->latest('placed_at')
                ->limit(8)
                ->get(['id', 'number', 'customer_id', 'status', 'payment_status', 'grand_total', 'placed_at']),
            'lowStock' => Product::query()
                ->where('track_inventory', true)
                ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
                ->with('vendor:id,name')
                ->orderBy('stock_quantity')
                ->limit(6)
                ->get(['id', 'name', 'sku', 'stock_quantity', 'low_stock_threshold', 'vendor_id']),
            'pending' => [
                'cancellations' => Cancellation::where('status', 'pending')->count(),
                'refunds' => Refund::where('status', 'pending')->count(),
                'vendors' => Vendor::where('status', 'pending')->count(),
                'unfulfilled' => Order::where('fulfillment_status', '!=', 'fulfilled')
                    ->whereNotIn('status', ['cancelled', 'refunded'])->count(),
            ],
        ]);
    }

    /**
     * @return array{revenue: float, orders: int}
     */
    protected function totals(Carbon $from, Carbon $to): array
    {
        $query = Order::whereBetween('placed_at', [$from, $to])->whereNot('status', 'cancelled');

        return [
            'revenue' => (float) (clone $query)->sum('grand_total'),
            'orders' => (clone $query)->count(),
        ];
    }

    protected function change(float $current, float $previous): ?float
    {
        if ($previous <= 0) {
            return $current > 0 ? 100.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * @return array<int, array{label: string, value: float}>
     */
    protected function salesSeries(Carbon $start, int $range): array
    {
        $rows = Order::query()
            ->selectRaw('date(placed_at) as day, sum(grand_total) as revenue')
            ->whereBetween('placed_at', [$start, Carbon::now()])
            ->whereNot('status', 'cancelled')
            ->groupBy('day')
            ->pluck('revenue', 'day');

        $series = [];

        for ($i = 0; $i < $range; $i++) {
            $day = (clone $start)->addDays($i);
            $key = $day->toDateString();

            $series[] = [
                'label' => $day->format($range > 90 ? 'M y' : 'd M'),
                'value' => (float) ($rows[$key] ?? 0),
            ];
        }

        return $series;
    }
}
