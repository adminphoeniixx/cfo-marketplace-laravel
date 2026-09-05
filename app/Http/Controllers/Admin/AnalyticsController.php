<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Cancellation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Ticket;
use App\Models\Vendor;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsController extends Controller
{
    /** Presets offered in the UI, in days. */
    public const RANGES = [7, 30, 90, 365];

    /** Reports that can be pulled out as CSV. */
    public const EXPORTS = ['vendors', 'products', 'categories', 'customers', 'orders'];

    /**
     * Which panel is rendering. Every report below already narrows to the
     * signed-in vendor through `resolveVendor()`, so the seller panel reuses
     * this controller wholesale and only swaps the page it renders.
     */
    protected string $panel = 'admin';

    public function index(Request $request): Response
    {
        [$from, $to, $preset] = $this->resolveRange($request);
        $vendorId = $this->resolveVendor($request);

        // Same-length window immediately before this one, for the deltas.
        $span = $from->diffInDays($to) + 1;
        $previousFrom = (clone $from)->subDays($span);
        $previousTo = (clone $from)->subSecond();

        $current = $this->totals($from, $to, $vendorId);
        $previous = $this->totals($previousFrom, $previousTo, $vendorId);

        $storeWide = $vendorId === null;

        return Inertia::render("{$this->panel}/analytics/Index", [
            'filters' => [
                'preset' => $preset,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'vendor' => $vendorId,
            ],
            'ranges' => self::RANGES,
            'vendors' => Vendor::query()
                ->when(! $storeWide, fn ($query) => $query->whereKey($vendorId))
                ->orderBy('name')
                ->get(['id', 'name']),
            'lockedToVendor' => $request->user()->isVendor(),
            'metrics' => [
                'gross_sales' => $this->metric($current['gross_sales'], $previous['gross_sales']),
                'orders' => $this->metric($current['orders'], $previous['orders']),
                'units' => $this->metric($current['units'], $previous['units']),
                'average_order' => $this->metric($current['average_order'], $previous['average_order']),
                'commission' => $this->metric($current['commission'], $previous['commission']),
                'vendor_earnings' => $this->metric($current['vendor_earnings'], $previous['vendor_earnings']),
                'tax' => $this->metric($current['tax'], $previous['tax']),
                'customers' => $storeWide
                    ? $this->metric(
                        Customer::whereBetween('created_at', [$from, $to])->count(),
                        Customer::whereBetween('created_at', [$previousFrom, $previousTo])->count(),
                    )
                    : null,
            ],
            'series' => $this->series($from, $to, $vendorId),
            'byVendor' => $this->byVendor($from, $to, $vendorId),
            'byCategory' => $this->byCategory($from, $to, $vendorId),
            'topProducts' => $this->topProducts($from, $to, $vendorId),
            'topCustomers' => $this->topCustomers($from, $to, $vendorId),
            'statusBreakdown' => $this->breakdown($from, $to, $vendorId, 'status'),
            'paymentBreakdown' => $this->breakdown($from, $to, $vendorId, 'payment_status'),
            'fulfillmentBreakdown' => $this->breakdown($from, $to, $vendorId, 'fulfillment_status'),
            'byPaymentMethod' => $this->byPaymentMethod($from, $to, $vendorId),
            'returns' => $storeWide ? [
                'refunds_count' => Refund::whereBetween('created_at', [$from, $to])->count(),
                'refunds_value' => (float) Refund::where('status', 'processed')
                    ->whereBetween('processed_at', [$from, $to])->sum('total_amount'),
                'cancellations_count' => Cancellation::whereBetween('created_at', [$from, $to])->count(),
                'cancellations_value' => (float) Cancellation::where('status', 'approved')
                    ->whereBetween('created_at', [$from, $to])->sum('total_amount'),
            ] : null,
            // Support is the marketplace's own desk, not a seller's, so it
            // does not appear when the screen is filtered to one store.
            'support' => $storeWide ? $this->support($from, $to) : null,
            'exports' => $this->exportsFor($request),
        ]);
    }

    /**
     * How the support desk did over the window.
     *
     * `first_response_hours` is the median rather than the mean, because one
     * ticket answered a fortnight late drags an average somewhere nobody
     * recognises while the median still describes the ordinary case. Both are
     * over tickets *opened* in the window that have since been answered —
     * anything still waiting has no first response to measure, and counting it
     * as zero would flatter the number exactly when it should not.
     *
     * @return array<string, mixed>
     */
    protected function support(Carbon $from, Carbon $to): array
    {
        $opened = Ticket::whereBetween('created_at', [$from, $to]);

        $answered = Ticket::whereBetween('created_at', [$from, $to])
            ->whereNotNull('first_responded_at')
            ->get(['created_at', 'first_responded_at'])
            ->map(fn (Ticket $ticket) => $ticket->hoursToFirstReply())
            ->filter(fn (?float $hours) => $hours !== null)
            ->sort()
            ->values();

        return [
            'opened' => (clone $opened)->count(),
            'resolved' => Ticket::whereIn('status', ['resolved', 'closed'])
                ->whereBetween('created_at', [$from, $to])
                ->count(),
            // Right now, not over the window: an open ticket is a person
            // waiting today, whatever month they wrote in.
            'open_now' => Ticket::unfinished()->count(),
            'unanswered_now' => Ticket::unfinished()->whereNull('first_responded_at')->count(),
            'first_response_hours' => $answered->isEmpty()
                ? null
                : round((float) $answered->median(), 1),
            'by_category' => Ticket::query()
                ->whereBetween('created_at', [$from, $to])
                ->selectRaw('category, count(*) as total')
                ->groupBy('category')
                ->orderByDesc('total')
                ->pluck('total', 'category')
                ->map(fn ($total, $category) => [
                    'label' => Ticket::CATEGORIES[$category] ?? $category,
                    'count' => (int) $total,
                ])
                ->values(),
        ];
    }

    /**
     * Stream one of the report tables as CSV, honouring the same filters as
     * the screen so the download always matches what is on it.
     */
    public function export(Request $request, string $report): StreamedResponse
    {
        abort_unless(in_array($report, $this->exportsFor($request), true), 404);

        [$from, $to] = $this->resolveRange($request);
        $vendorId = $this->resolveVendor($request);

        [$headers, $rows] = match ($report) {
            'vendors' => [
                ['Vendor', 'Orders', 'Units', 'Gross sales', 'Commission', 'Vendor earnings'],
                $this->byVendor($from, $to, $vendorId)->map(fn ($row) => [
                    $row->name, $row->orders_count, $row->units, $row->gross_sales, $row->commission, $row->vendor_earnings,
                ]),
            ],
            'products' => [
                ['Product', 'SKU', 'Units', 'Gross sales'],
                $this->topProducts($from, $to, $vendorId, null)->map(fn ($row) => [
                    $row->name, $row->sku, $row->units, $row->gross_sales,
                ]),
            ],
            'categories' => [
                ['Category', 'Units', 'Gross sales'],
                $this->byCategory($from, $to, $vendorId, null)->map(fn ($row) => [
                    $row->name, $row->units, $row->gross_sales,
                ]),
            ],
            'customers' => [
                ['Customer', 'Email', 'Orders', 'Spend'],
                $this->topCustomers($from, $to, $vendorId, null)->map(fn ($row) => [
                    $row->name, $row->email, $row->orders_count, $row->spend,
                ]),
            ],
            default => [
                ['Order', 'Placed at', 'Customer', 'Status', 'Payment', 'Items', 'Gross sales'],
                $this->orderRows($from, $to, $vendorId)->map(fn ($row) => [
                    $row->number,
                    $row->placed_at,
                    trim(($row->first_name ?? '').' '.($row->last_name ?? '')) ?: 'Guest',
                    $row->status,
                    $row->payment_status,
                    $row->units,
                    $row->gross_sales,
                ]),
            ],
        };

        $filename = sprintf('%s-%s-to-%s.csv', $report, $from->toDateString(), $to->toDateString());

        return response()->streamDownload(function () use ($headers, $rows) {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            // PHP 8.4 deprecates the implicit backslash escape; an empty one is
            // both the future default and the correct behaviour for CSV.
            fputcsv($handle, $headers, ',', '"', '');

            foreach ($rows as $row) {
                fputcsv($handle, $row, ',', '"', '');
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Reports this caller may pull as CSV.
     *
     * A vendor never gets `vendors`: that report is the marketplace
     * leaderboard, and narrowed to one store it is a single row of numbers
     * they already have on the rest of the screen. One rule here covers the
     * marketplace panel, the seller panel and the seller API.
     *
     * @return list<string>
     */
    protected function exportsFor(Request $request): array
    {
        return $request->user()->isVendor()
            ? array_values(array_diff(self::EXPORTS, ['vendors']))
            : self::EXPORTS;
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: int|null}
     */
    protected function resolveRange(Request $request): array
    {
        // An explicit from/to pair wins; otherwise fall back to a day preset.
        if ($request->filled('from') && $request->filled('to')) {
            $from = Carbon::parse($request->date('from'))->startOfDay();
            $to = Carbon::parse($request->date('to'))->endOfDay();

            if ($to->lessThan($from)) {
                [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
            }

            return [$from, $to, null];
        }

        $preset = (int) $request->integer('preset', 30);
        $preset = in_array($preset, self::RANGES, true) ? $preset : 30;

        return [Carbon::today()->subDays($preset - 1), Carbon::now()->endOfDay(), $preset];
    }

    protected function resolveVendor(Request $request): ?int
    {
        $user = $request->user();

        if ($user->isVendor()) {
            return (int) $user->vendor_id;
        }

        return $request->filled('vendor') ? $request->integer('vendor') : null;
    }

    /**
     * Line items in the window, excluding cancelled orders. Every report is
     * built on this so a vendor filter applies consistently everywhere.
     */
    protected function items(Carbon $from, Carbon $to, ?int $vendorId): Builder
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereBetween('orders.placed_at', [$from, $to])
            ->whereNot('orders.status', 'cancelled')
            ->when($vendorId, fn (BuilderContract $query) => $query->where('order_items.vendor_id', $vendorId));
    }

    /**
     * @return array{gross_sales: float, orders: int, units: int, average_order: float, commission: float, vendor_earnings: float, tax: float}
     */
    protected function totals(Carbon $from, Carbon $to, ?int $vendorId): array
    {
        $row = $this->items($from, $to, $vendorId)
            ->selectRaw('coalesce(sum(order_items.total), 0) as gross_sales')
            ->selectRaw('coalesce(sum(order_items.quantity), 0) as units')
            ->selectRaw('coalesce(sum(order_items.commission_amount), 0) as commission')
            ->selectRaw('coalesce(sum(order_items.vendor_earning), 0) as vendor_earnings')
            ->selectRaw('coalesce(sum(order_items.tax_amount), 0) as tax')
            ->selectRaw('count(distinct order_items.order_id) as orders')
            ->first();

        $orders = (int) ($row->orders ?? 0);
        $gross = round((float) ($row->gross_sales ?? 0), 2);

        return [
            'gross_sales' => $gross,
            'orders' => $orders,
            'units' => (int) ($row->units ?? 0),
            'average_order' => $orders > 0 ? round($gross / $orders, 2) : 0.0,
            'commission' => round((float) ($row->commission ?? 0), 2),
            'vendor_earnings' => round((float) ($row->vendor_earnings ?? 0), 2),
            'tax' => round((float) ($row->tax ?? 0), 2),
        ];
    }

    /**
     * @return array{value: float|int, change: float|null}
     */
    protected function metric(float|int $current, float|int $previous): array
    {
        return [
            'value' => $current,
            'change' => $previous <= 0
                ? ($current > 0 ? 100.0 : null)
                : round((($current - $previous) / $previous) * 100, 1),
        ];
    }

    /**
     * Daily revenue, rolled up to weeks or months once the window gets long
     * enough that a point per day stops being readable.
     *
     * @return array{bucket: string, points: array<int, array{label: string, value: float}>}
     */
    protected function series(Carbon $from, Carbon $to, ?int $vendorId): array
    {
        $daily = $this->items($from, $to, $vendorId)
            ->selectRaw('date(orders.placed_at) as day')
            ->selectRaw('coalesce(sum(order_items.total), 0) as revenue')
            ->groupBy('day')
            ->pluck('revenue', 'day');

        $days = $from->diffInDays($to) + 1;
        $bucket = $days > 180 ? 'month' : ($days > 62 ? 'week' : 'day');

        $points = [];

        for ($i = 0; $i < $days; $i++) {
            $day = (clone $from)->addDays($i);
            $value = (float) ($daily[$day->toDateString()] ?? 0);

            $key = match ($bucket) {
                'month' => $day->format('M y'),
                'week' => $day->copy()->startOfWeek()->format('d M'),
                default => $day->format('d M'),
            };

            $points[$key] = ($points[$key] ?? 0) + $value;
        }

        return [
            'bucket' => $bucket,
            'points' => collect($points)
                ->map(fn ($value, $label) => ['label' => (string) $label, 'value' => round($value, 2)])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return Collection<int, \stdClass>
     */
    protected function byVendor(Carbon $from, Carbon $to, ?int $vendorId)
    {
        return $this->items($from, $to, $vendorId)
            ->join('vendors', 'vendors.id', '=', 'order_items.vendor_id')
            ->selectRaw('vendors.id, vendors.name')
            ->selectRaw('count(distinct order_items.order_id) as orders_count')
            ->selectRaw('coalesce(sum(order_items.quantity), 0) as units')
            ->selectRaw('coalesce(sum(order_items.total), 0) as gross_sales')
            ->selectRaw('coalesce(sum(order_items.commission_amount), 0) as commission')
            ->selectRaw('coalesce(sum(order_items.vendor_earning), 0) as vendor_earnings')
            ->groupBy('vendors.id', 'vendors.name')
            ->orderByDesc('gross_sales')
            ->get();
    }

    /**
     * @return Collection<int, \stdClass>
     */
    protected function byCategory(Carbon $from, Carbon $to, ?int $vendorId, ?int $limit = 8)
    {
        return $this->items($from, $to, $vendorId)
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->selectRaw('categories.name')
            ->selectRaw('coalesce(sum(order_items.quantity), 0) as units')
            ->selectRaw('coalesce(sum(order_items.total), 0) as gross_sales')
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('gross_sales')
            ->when($limit, fn (BuilderContract $query) => $query->limit($limit))
            ->get();
    }

    /**
     * @return Collection<int, \stdClass>
     */
    protected function topProducts(Carbon $from, Carbon $to, ?int $vendorId, ?int $limit = 10)
    {
        return $this->items($from, $to, $vendorId)
            ->selectRaw('order_items.name, order_items.sku')
            ->selectRaw('coalesce(sum(order_items.quantity), 0) as units')
            ->selectRaw('coalesce(sum(order_items.total), 0) as gross_sales')
            ->groupBy('order_items.name', 'order_items.sku')
            ->orderByDesc('gross_sales')
            ->when($limit, fn (BuilderContract $query) => $query->limit($limit))
            ->get();
    }

    /**
     * @return Collection<int, \stdClass>
     */
    protected function topCustomers(Carbon $from, Carbon $to, ?int $vendorId, ?int $limit = 10)
    {
        return $this->items($from, $to, $vendorId)
            ->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->selectRaw("customers.first_name || ' ' || coalesce(customers.last_name, '') as name")
            ->selectRaw('customers.email')
            ->selectRaw('count(distinct order_items.order_id) as orders_count')
            ->selectRaw('coalesce(sum(order_items.total), 0) as spend')
            ->groupBy('customers.id', 'customers.first_name', 'customers.last_name', 'customers.email')
            ->orderByDesc('spend')
            ->when($limit, fn (BuilderContract $query) => $query->limit($limit))
            ->get();
    }

    /**
     * Orders and revenue per payment method, so the mix is visible next to the
     * rest of the reporting.
     *
     * @return Collection<int, \stdClass>
     */
    protected function byPaymentMethod(Carbon $from, Carbon $to, ?int $vendorId)
    {
        return $this->items($from, $to, $vendorId)
            ->selectRaw("coalesce(orders.payment_method, 'Not specified') as name")
            ->selectRaw('count(distinct order_items.order_id) as orders_count')
            ->selectRaw('coalesce(sum(order_items.total), 0) as gross_sales')
            ->groupBy('orders.payment_method')
            ->orderByDesc('gross_sales')
            ->get();
    }

    /**
     * Order counts per status column, vendor-aware.
     *
     * @return array<string, int>
     */
    protected function breakdown(Carbon $from, Carbon $to, ?int $vendorId, string $column): array
    {
        $select = match ($column) {
            'payment_status' => 'payment_status as bucket, count(*) as total',
            'fulfillment_status' => 'fulfillment_status as bucket, count(*) as total',
            default => 'status as bucket, count(*) as total',
        };

        return Order::query()
            ->whereBetween('placed_at', [$from, $to])
            ->when($vendorId, fn ($query) => $query->whereHas(
                'items', fn ($q) => $q->where('vendor_id', $vendorId)
            ))
            ->selectRaw($select)
            ->groupBy($column)
            ->orderByDesc('total')
            ->pluck('total', 'bucket')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * @return Collection<int, \stdClass>
     */
    protected function orderRows(Carbon $from, Carbon $to, ?int $vendorId)
    {
        return $this->items($from, $to, $vendorId)
            ->leftJoin('customers', 'customers.id', '=', 'orders.customer_id')
            ->selectRaw('orders.number, orders.placed_at, orders.status, orders.payment_status')
            ->selectRaw('customers.first_name, customers.last_name')
            ->selectRaw('coalesce(sum(order_items.quantity), 0) as units')
            ->selectRaw('coalesce(sum(order_items.total), 0) as gross_sales')
            ->groupBy(
                'orders.id', 'orders.number', 'orders.placed_at', 'orders.status',
                'orders.payment_status', 'customers.first_name', 'customers.last_name',
            )
            ->orderByDesc('orders.placed_at')
            ->get();
    }
}
