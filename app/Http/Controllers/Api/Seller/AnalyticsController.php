<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The time series the dashboard chart needs. `/dashboard` answers "how am I
 * doing"; this answers "how did that change day by day".
 */
class AnalyticsController extends Controller
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
}
