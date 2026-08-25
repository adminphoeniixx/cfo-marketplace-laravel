<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Admin\AnalyticsController as MarketplaceAnalyticsController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A seller's numbers, in two shapes.
 *
 * `sales()` is the light one the dashboard chart wants: a day-by-day series
 * and nothing else. `report()` is the full picture the seller panel renders —
 * period-over-period deltas, top products, categories and customers, status
 * breakdowns — and `export()` is inherited whole.
 *
 * The reports come from the marketplace controller, whose every query is built
 * on `resolveVendor()`. That returns the signed-in user's own store for a
 * vendor and ignores the request, so subclassing costs nothing in scoping and
 * saves keeping two copies of the margin maths in step.
 */
class AnalyticsController extends MarketplaceAnalyticsController
{
    use ScopesToStore;

    public function sales(Request $request): JsonResponse
    {
        $storeId = $this->storeId($request);
        $days = min(max($request->integer('days', 30), 1), 365);
        $to = Carbon::now()->endOfDay();
        $from = $to->copy()->subDays($days - 1)->startOfDay();

        $byDay = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.vendor_id', $storeId)
            ->whereNot('orders.status', 'cancelled')
            ->whereBetween('orders.placed_at', [$from, $to])
            ->selectRaw('date(orders.placed_at) as day')
            ->selectRaw('coalesce(sum(order_items.total), 0) as sales')
            ->selectRaw('coalesce(sum(order_items.vendor_earning), 0) as earning')
            ->selectRaw('coalesce(sum(order_items.quantity), 0) as units')
            ->selectRaw('count(distinct order_items.order_id) as orders')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        // Days with no sales still need a point, or the chart lies about shape.
        $series = [];

        for ($day = $from->copy(); $day->lte($to); $day = $day->addDay()) {
            $row = $byDay[$day->toDateString()] ?? null;

            $series[] = [
                'date' => $day->toDateString(),
                'sales' => round((float) ($row->sales ?? 0), 2),
                'earning' => round((float) ($row->earning ?? 0), 2),
                'orders' => (int) ($row->orders ?? 0),
                'units' => (int) ($row->units ?? 0),
            ];
        }

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $days,
            'totals' => [
                'sales' => round(array_sum(array_column($series, 'sales')), 2),
                'earning' => round(array_sum(array_column($series, 'earning')), 2),
                'orders' => array_sum(array_column($series, 'orders')),
                'units' => array_sum(array_column($series, 'units')),
            ],
            'series' => $series,
        ]);
    }

    /**
     * The full report, matching what the seller panel's analytics screen shows.
     *
     * Accepts the same query string as the panel — `preset`, or `from`/`to` —
     * so an app and the panel can be pointed at the same window and agree.
     */
    public function report(Request $request): JsonResponse
    {
        [$from, $to, $preset] = $this->resolveRange($request);
        $storeId = $this->storeId($request);

        // Same-length window immediately before this one, for the deltas.
        $span = $from->diffInDays($to) + 1;
        $previousFrom = (clone $from)->subDays($span);
        $previousTo = (clone $from)->subSecond();

        $current = $this->totals($from, $to, $storeId);
        $previous = $this->totals($previousFrom, $previousTo, $storeId);

        return response()->json([
            'window' => [
                'preset' => $preset,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'metrics' => collect($current)
                ->map(fn ($value, $key) => $this->metric($value, $previous[$key] ?? 0))
                ->all(),
            'series' => $this->series($from, $to, $storeId),
            'by_category' => $this->byCategory($from, $to, $storeId),
            'top_products' => $this->topProducts($from, $to, $storeId),
            'top_customers' => $this->topCustomers($from, $to, $storeId),
            'by_payment_method' => $this->byPaymentMethod($from, $to, $storeId),
            'status_breakdown' => $this->breakdown($from, $to, $storeId, 'status'),
            'payment_breakdown' => $this->breakdown($from, $to, $storeId, 'payment_status'),
            'fulfillment_breakdown' => $this->breakdown($from, $to, $storeId, 'fulfillment_status'),
            // Deliberately no `by_vendor`: for a seller that is one row, their
            // own, and the marketplace leaderboard is not theirs to read. The
            // export list is narrowed by the same rule, in the parent.
            'exports' => $this->exportsFor($request),
        ]);
    }
}
