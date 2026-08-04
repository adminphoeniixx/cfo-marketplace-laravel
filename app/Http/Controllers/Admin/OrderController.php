<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Vendor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
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
            ->when($request->string('fulfillment_status')->toString(), fn ($query, $status) => $query->where('fulfillment_status', $status))
            ->when($request->filled('vendor'), fn ($query) => $query->whereHas(
                'items', fn ($q) => $q->where('vendor_id', $request->integer('vendor'))
            ))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('placed_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('placed_at', '<=', $request->date('to')))
            ->latest('placed_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('admin/orders/Index', [
            'orders' => $orders,
            'filters' => $request->only(['search', 'status', 'payment_status', 'fulfillment_status', 'vendor', 'from', 'to']),
            'vendors' => Vendor::orderBy('name')->get(['id', 'name']),
            'statuses' => Order::STATUSES,
            'paymentStatuses' => Order::PAYMENT_STATUSES,
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

        return Inertia::render('admin/orders/Show', [
            'order' => $order,
            'statuses' => Order::STATUSES,
            'paymentStatuses' => Order::PAYMENT_STATUSES,
            'fulfillmentStatuses' => Order::FULFILLMENT_STATUSES,
            'vendorBreakdown' => $order->items
                ->groupBy(fn (OrderItem $item) => $item->vendor->name ?? 'Store')
                ->map(fn ($items) => [
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

        $order->recordEvent(
            'status',
            "Status changed from {$from} to {$data['status']}",
            $data['note'] ?? null,
            ['from' => $from, 'to' => $data['status']],
        );

        return back()->with('success', "Order marked as {$data['status']}.");
    }

    public function updatePayment(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'payment_status' => ['required', Rule::in(Order::PAYMENT_STATUSES)],
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
