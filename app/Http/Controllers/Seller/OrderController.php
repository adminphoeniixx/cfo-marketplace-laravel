<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Api\Seller\ScopesToStore;
use App\Http\Controllers\Controller;
use App\Models\DeliveryPartner;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The seller panel's order screens — the web twin of the seller API's
 * `OrderController`, down to the fulfilment rules.
 *
 * Two things this deliberately does not offer, because they belong to the
 * marketplace and not to one seller: changing the order's status, and changing
 * its payment state. A seller packs their own lines and leaves a note; the
 * rest is the admin's call.
 *
 * Manual order entry lives next door in `ManualOrderController`, which does
 * subclass the marketplace controller — that flow already locks a vendor to
 * their own store, so there is nothing to re-scope there.
 */
class OrderController extends Controller
{
    use ScopesToStore;

    public function index(Request $request): Response
    {
        $orders = $this->storeOrders($request)
            ->with('customer:id,first_name,last_name,email')
            ->when($request->string('search')->toString(), fn ($query, $search) => $query
                ->where('number', 'like', "%{$search}%"))
            ->when($request->string('status')->toString(), fn ($query, $status) => $query
                ->where('status', $status))
            ->when($request->string('fulfillment_status')->toString(), fn ($query, $status) => $query
                ->where('fulfillment_status', $status))
            ->when($request->boolean('needs_packing'), fn ($query) => $query
                ->whereIn('id', $this->ordersNeedingPacking($request)->select('orders.id')))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('placed_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('placed_at', '<=', $request->date('to')))
            ->latest('placed_at')
            ->paginate(20)
            ->withQueryString();

        // Money is summed from this store's own lines. The order's
        // `grand_total` is the whole basket, which on a shared basket includes
        // another seller's goods — never show that here.
        $orders->getCollection()->transform(function (Order $order) {
            $order->setAttribute('mine', [
                'items' => $order->items->sum('quantity'),
                'total' => round((float) $order->items->sum('total'), 2),
                'earning' => round((float) $order->items->sum('vendor_earning'), 2),
                'to_pack' => $order->items->sum(fn ($item) => max(
                    0,
                    $item->quantity - $item->quantity_cancelled - $item->quantity_fulfilled,
                )),
            ]);

            return $order;
        });

        $counts = $this->storeOrders($request)
            ->getQuery()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return Inertia::render('seller/orders/Index', [
            'orders' => $orders,
            'filters' => $request->only(['search', 'status', 'fulfillment_status', 'needs_packing', 'from', 'to']),
            'statuses' => Order::STATUSES,
            'fulfillmentStatuses' => Order::FULFILLMENT_STATUSES,
            'counts' => collect(Order::STATUSES)
                ->mapWithKeys(fn (string $status) => [$status => (int) ($counts[$status] ?? 0)])
                ->put('all', (int) $counts->sum()),
            'summary' => [
                'to_pack' => $this->ordersNeedingPacking($request)->count(),
                'earnings' => round((float) $this->storeEarnings($request), 2),
            ],
        ]);
    }

    public function show(Request $request, int $order): Response
    {
        $model = $this->findOwnedOrder($request, $order);

        // `items` is re-stated with its constraint on purpose: naming
        // `items.product` alone would re-load the relation unscoped and pull
        // another seller's lines back into the payload.
        $model->load([
            'customer:id,first_name,last_name,email,phone',
            'items' => fn ($query) => $query->where('vendor_id', $this->storeId($request)),
            'items.product:id,name,slug',
            'events',
        ]);

        return Inertia::render('seller/orders/Show', [
            'order' => $model,
            // Same shape the API serves: another seller's events dropped, and
            // marketplace staff named as "marketplace" rather than by name.
            'timeline' => $this->storeTimeline($model, $this->storeId($request)),
            'mine' => [
                'items' => $model->items->sum('quantity'),
                'total' => round((float) $model->items->sum('total'), 2),
                'commission' => round((float) $model->items->sum('commission_amount'), 2),
                'earning' => round((float) $model->items->sum('vendor_earning'), 2),
            ],
            // `$model->items` is the eager-loaded, store-scoped set; the
            // relation query is the whole basket. A gap between them means
            // another seller is on this order — say so, rather than letting
            // the totals look like they do not add up.
            'sharedBasket' => $model->items->count() < $model->items()->count(),
            'deliveryPartners' => DeliveryPartner::active()
                ->orderBy('position')->orderBy('name')->pluck('name'),
            'trackingUrl' => DeliveryPartner::where('name', $model->carrier)->first()
                ?->trackingUrlFor($model->tracking_number),
        ]);
    }

    /**
     * Mark this store's lines as packed. Lifted from the seller API so both
     * clients recompute the order-level status the same way.
     */
    public function fulfill(Request $request, int $order): RedirectResponse
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
                // Scoped find: another vendor's order_item id 404s.
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

            // Deliberately unscoped: the whole order decides the order status,
            // so a two-vendor basket does not read as shipped when half of it is.
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
                ['vendor_id' => $storeId, 'source' => 'seller-panel'],
            );
        });

        return back()->with('success', 'Fulfilment updated.');
    }

    public function addNote(Request $request, int $order): RedirectResponse
    {
        $model = $this->findOwnedOrder($request, $order);

        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
        ]);

        $model->recordEvent(
            'note',
            'Note from '.$request->user()->vendor->name,
            $data['note'],
            ['vendor_id' => $this->storeId($request), 'source' => 'seller-panel'],
        );

        return back()->with('success', 'Note added.');
    }

    private function storeEarnings(Request $request): float
    {
        return (float) OrderItem::query()
            ->where('vendor_id', $this->storeId($request))
            ->whereHas('order', fn ($query) => $query->whereNot('status', 'cancelled'))
            ->sum('vendor_earning');
    }
}
