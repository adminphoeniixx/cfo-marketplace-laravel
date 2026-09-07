<?php

namespace App\Http\Controllers\Admin;

use App\Actions\CreateManualOrder;
use App\Actions\Shipping\CancelShipment;
use App\Actions\Shipping\FetchLabel;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\DeliveryPartner;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Vendor;
use App\Notifications\OrderStatusChanged;
use App\Services\Notifier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\RedirectResponse as BaseRedirect;

class OrderController extends Controller
{
    /**
     * Which panel is rendering. The seller panel subclasses this controller to
     * inherit manual order entry — which already locks a vendor to their own
     * store — while overriding the screens that read the whole marketplace.
     */
    protected string $panel = 'admin';

    public function index(Request $request): Response
    {
        $orders = Order::query()
            ->with('customer:id,first_name,last_name,email')
            ->withCount('items')
            ->when($request->string('search')->toString(), fn ($query, $search) => $query->where(
                fn ($q) => $q->where('number', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('tracking_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%"))
            ))
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->when($request->string('payment_status')->toString(), fn ($query, $status) => $query->where('payment_status', $status))
            ->when($request->string('payment_method')->toString(), fn ($query, $method) => $query->where('payment_method', $method))
            ->when($request->string('fulfillment_status')->toString(), fn ($query, $status) => $query->where('fulfillment_status', $status))
            ->when($request->filled('vendor'), fn ($query) => $query->whereHas(
                'items', fn ($q) => $q->where('vendor_id', $request->integer('vendor'))
            ))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('placed_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('placed_at', '<=', $request->date('to')))
            ->latest('placed_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render("{$this->panel}/orders/Index", [
            'orders' => $orders,
            'filters' => $request->only(['search', 'status', 'payment_status', 'payment_method', 'fulfillment_status', 'vendor', 'from', 'to']),
            'vendors' => Vendor::orderBy('name')->get(['id', 'name']),
            'statuses' => Order::STATUSES,
            'paymentStatuses' => Order::PAYMENT_STATUSES,
            'paymentMethods' => PaymentMethod::orderBy('position')->orderBy('name')->pluck('name'),
            'counts' => collect(Order::STATUSES)
                ->mapWithKeys(fn ($status) => [$status => Order::where('status', $status)->count()])
                ->put('all', Order::count()),
            'summary' => [
                'revenue' => (float) Order::whereNot('status', 'cancelled')->sum('grand_total'),
                'unfulfilled' => Order::where('fulfillment_status', 'unfulfilled')
                    ->whereNotIn('status', ['cancelled', 'refunded'])->count(),
                'unpaid' => Order::where('payment_status', 'pending')->count(),
            ],
        ]);
    }

    /**
     * Manual order entry. Admins pick any vendor; a vendor user is locked to
     * their own store. Products are loaded per vendor so the picker only ever
     * shows what that vendor actually sells.
     */
    public function create(Request $request): Response
    {
        $user = $request->user();

        $vendors = Vendor::query()
            ->when($user->isVendor(), fn ($query) => $query->whereKey($user->vendor_id))
            ->orderBy('name')
            ->get(['id', 'name', 'commission_rate']);

        $vendorId = $user->isVendor()
            ? $user->vendor_id
            : ($request->filled('vendor') ? $request->integer('vendor') : $vendors->first()?->id);

        return Inertia::render("{$this->panel}/orders/Create", [
            'vendors' => $vendors,
            'selectedVendor' => $vendorId,
            'lockedToVendor' => $user->isVendor(),
            'products' => fn () => CreateManualOrder::sellableProducts($vendorId)->map(fn (Product $product) => [
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
            // Marketplace staff pick from every customer; a seller only from
            // people who have already bought from them. Handing a seller the
            // whole book would be handing them 200 strangers' emails and
            // phone numbers, which is not theirs to have.
            'customers' => $this->customerPicker($user->isVendor() ? (int) $user->vendor_id : null),
            'statuses' => Order::STATUSES,
            'paymentStatuses' => Order::PAYMENT_STATUSES,
            'paymentMethods' => PaymentMethod::active()
                ->orderBy('position')->orderBy('name')->pluck('name'),
        ]);
    }

    /**
     * Customers the caller may raise an order for. Scoped to people who have
     * bought from this store when a vendor is asking.
     *
     * @return Collection<int, Customer>
     */
    protected function customerPicker(?int $vendorId): Collection
    {
        return Customer::query()
            ->where('status', 'active')
            ->when($vendorId, fn ($query) => $query->whereHas(
                'orders.items',
                fn ($items) => $items->where('vendor_id', $vendorId),
            ))
            ->orderBy('first_name')
            ->limit(200)
            ->get(['id', 'first_name', 'last_name', 'email', 'phone']);
    }

    public function store(Request $request, CreateManualOrder $action): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
            ...CreateManualOrder::rules(),
        ]);

        // A vendor user may only ever raise orders for their own store.
        $vendorId = $user->isVendor() ? (int) $user->vendor_id : (int) $data['vendor_id'];

        $order = $action->handle(
            $data,
            Vendor::findOrFail($vendorId),
            $user,
            $this->panel === 'seller' ? 'seller-panel' : 'admin',
        );

        return to_route("{$this->panel}.orders.show", $order)
            ->with('success', "Order {$order->number} created.");
    }

    public function show(Order $order): Response
    {
        $order->load([
            'customer.addresses',
            'items.product:id,name,slug',
            'items.vendor:id,name',
            'events.user:id,name',
            'cancellations.items',
            'refunds.items',
        ]);

        return Inertia::render("{$this->panel}/orders/Show", [
            'order' => $order,
            'statuses' => Order::STATUSES,
            'paymentStatuses' => Order::PAYMENT_STATUSES,
            'fulfillmentStatuses' => Order::FULFILLMENT_STATUSES,
            'paymentMethods' => PaymentMethod::active()
                ->orderBy('position')->orderBy('name')->pluck('name'),
            'deliveryPartners' => DeliveryPartner::active()
                ->orderBy('position')->orderBy('name')->pluck('name'),
            'trackingUrl' => DeliveryPartner::where('name', $order->carrier)->first()
                ?->trackingUrlFor($order->tracking_number),
            'vendorBreakdown' => $order->items
                ->groupBy(fn (OrderItem $item) => $item->vendor->name ?? 'Store')
                ->map(fn ($items) => [
                    // The id as well as the totals, so the row can link to
                    // that seller's own invoice for their part of the order.
                    'vendor_id' => $items->first()->vendor_id,
                    'items' => $items->count(),
                    'total' => round((float) $items->sum('total'), 2),
                    'commission' => round((float) $items->sum('commission_amount'), 2),
                    'earning' => round((float) $items->sum('vendor_earning'), 2),
                ]),
        ]);
    }

    public function updateStatus(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(Order::STATUSES)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $from = $order->status;

        $timestamps = match ($data['status']) {
            'shipped' => ['shipped_at' => now()],
            'completed' => ['delivered_at' => now()],
            'cancelled' => ['cancelled_at' => now()],
            default => [],
        };

        $order->update(['status' => $data['status'], ...$timestamps]);

        /*
        | Tell the courier too.
        |
        | Cancelling here used to cancel the order and nothing else: the
        | waybill stayed live, the van still came, and on a cash-on-delivery
        | parcel somebody could still be asked for money at a door. It is
        | never fatal — a courier refusing must not undo a cancellation the
        | shopper has already been told about — and it writes its own line on
        | the timeline either way.
        */
        if ($data['status'] === 'cancelled' && $from !== 'cancelled') {
            app(CancelShipment::class)->handle($order->fresh());
        }

        $order->recordEvent(
            'status',
            "Status changed from {$from} to {$data['status']}",
            $data['note'] ?? null,
            ['from' => $from, 'to' => $data['status']],
        );

        // A seller who has already packed an order needs to hear that it was
        // cancelled or held; the rest of the ladder is noise to them.
        if (OrderStatusChanged::worthTelling($from, $data['status'])) {
            Notifier::send(new OrderStatusChanged($order->fresh('items')), $request->user());
        }

        return back()->with('success', "Order marked as {$data['status']}.");
    }

    /**
     * Print the label, by sending staff straight to the courier's own PDF.
     *
     * The same document the seller prints, reachable from the marketplace's
     * own order screen — because a support call about a parcel is answered by
     * looking at what is actually on the box.
     */
    public function label(Request $request, Order $order, FetchLabel $labels): BaseRedirect
    {
        $url = $labels->handle($order, $request->boolean('refresh'));

        if ($url === null) {
            return back()->with('error', $order->tracking_number
                ? 'The courier has no label for this parcel yet. Try again in a minute.'
                : 'This order has no booking to print a label for.');
        }

        return redirect()->away($url);
    }

    public function updatePayment(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'payment_status' => ['required', Rule::in(Order::PAYMENT_STATUSES)],
            'payment_method' => ['nullable', 'string', 'max:60'],
            'transaction_id' => ['nullable', 'string', 'max:120'],
        ]);

        $order->update([
            ...$data,
            'paid_at' => $data['payment_status'] === 'paid' ? ($order->paid_at ?? now()) : $order->paid_at,
        ]);

        $order->recordEvent('payment', "Payment marked as {$data['payment_status']}");

        return back()->with('success', 'Payment status updated.');
    }

    public function fulfill(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'exists:order_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:0'],
            'tracking_number' => ['nullable', 'string', 'max:120'],
            'carrier' => ['nullable', 'string', 'max:80'],
            'notify_customer' => ['boolean'],
        ]);

        DB::transaction(function () use ($order, $data) {
            foreach ($data['items'] as $row) {
                $item = $order->items()->findOrFail((int) $row['id']);
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

            $order->load('items');

            $expected = $order->items->sum(fn ($item) => $item->quantity - $item->quantity_cancelled);
            $fulfilled = $order->items->sum('quantity_fulfilled');

            $order->update([
                'fulfillment_status' => match (true) {
                    $fulfilled <= 0 => 'unfulfilled',
                    $fulfilled >= $expected => 'fulfilled',
                    default => 'partially_fulfilled',
                },
                'tracking_number' => $data['tracking_number'] ?? $order->tracking_number,
                'carrier' => $data['carrier'] ?? $order->carrier,
                'shipped_at' => $order->shipped_at ?? now(),
                'status' => $order->status === 'processing' && $fulfilled >= $expected ? 'shipped' : $order->status,
            ]);

            $order->recordEvent(
                'fulfillment',
                'Fulfillment updated',
                empty($data['tracking_number']) ? null : "Tracking: {$data['tracking_number']}",
                ['carrier' => $data['carrier'] ?? null],
            );
        });

        return back()->with('success', 'Fulfillment updated.');
    }

    public function addNote(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:1000'],
        ]);

        $order->recordEvent('note', 'Note added', $data['body']);

        return back()->with('success', 'Note added to the timeline.');
    }

    public function update(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'admin_note' => ['nullable', 'string', 'max:2000'],
            'shipping_method' => ['nullable', 'string', 'max:120'],
            'tracking_number' => ['nullable', 'string', 'max:120'],
            'carrier' => ['nullable', 'string', 'max:80'],
            'shipping_address' => ['nullable', 'array'],
            'billing_address' => ['nullable', 'array'],
        ]);

        $order->update($data);

        return back()->with('success', 'Order updated.');
    }
}
