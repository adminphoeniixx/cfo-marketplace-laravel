<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Notifications\RefundDecided;
use App\Notifications\RefundRequested;
use App\Services\Notifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RefundController extends Controller
{
    public function index(Request $request): Response
    {
        $refunds = Refund::query()
            ->with(['order:id,number,grand_total', 'customer:id,first_name,last_name,email'])
            ->withCount('items')
            ->when($request->string('search')->toString(), fn ($query, $search) => $query->where(
                fn ($q) => $q->where('number', 'like', "%{$search}%")
                    ->orWhereHas('order', fn ($o) => $o->where('number', 'like', "%{$search}%"))
            ))
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->when($request->string('reason')->toString(), fn ($query, $reason) => $query->where('reason', $reason))
            ->when($request->string('method')->toString(), fn ($query, $method) => $query->where('method', $method))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('admin/refunds/Index', [
            'refunds' => $refunds,
            'filters' => $request->only(['search', 'status', 'reason', 'method']),
            'reasons' => Refund::REASONS,
            'methods' => Refund::METHODS,
            'counts' => [
                'all' => Refund::count(),
                'pending' => Refund::where('status', 'pending')->count(),
                'approved' => Refund::where('status', 'approved')->count(),
                'processed' => Refund::where('status', 'processed')->count(),
                'rejected' => Refund::where('status', 'rejected')->count(),
            ],
            'summary' => [
                'pending_value' => (float) Refund::where('status', 'pending')->sum('total_amount'),
                'processed_value' => (float) Refund::where('status', 'processed')->sum('total_amount'),
                'this_month' => (float) Refund::where('status', 'processed')
                    ->whereMonth('processed_at', now()->month)
                    ->whereYear('processed_at', now()->year)
                    ->sum('total_amount'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $order = Order::with('items')->findOrFail($request->integer('order'));

        return Inertia::render('admin/refunds/Form', [
            'order' => $order,
            'reasons' => Refund::REASONS,
            'methods' => Refund::METHODS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'reason' => ['required', Rule::in(array_keys(Refund::REASONS))],
            'method' => ['required', Rule::in(array_keys(Refund::METHODS))],
            'note' => ['nullable', 'string', 'max:1000'],
            'restock' => ['boolean'],
            'shipping_amount' => ['nullable', 'numeric', 'min:0'],
            'adjustment_amount' => ['nullable', 'numeric'],
            'items' => ['array'],
            'items.*.order_item_id' => ['required', 'integer', 'exists:order_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $order = Order::with('items')->findOrFail((int) $data['order_id']);

        $refund = DB::transaction(function () use ($order, $data) {
            $refund = Refund::create([
                'number' => Refund::nextNumber(),
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'reason' => $data['reason'],
                'method' => $data['method'],
                'note' => $data['note'] ?? null,
                'restock' => $data['restock'] ?? true,
                'shipping_amount' => $data['shipping_amount'] ?? 0,
                'adjustment_amount' => $data['adjustment_amount'] ?? 0,
                'status' => 'pending',
            ]);

            $itemsTotal = 0;
            $taxTotal = 0;

            foreach ($data['items'] ?? [] as $row) {
                $item = $order->items->firstWhere('id', $row['order_item_id']);

                if (! $item) {
                    continue;
                }

                $quantity = min($row['quantity'], $item->quantity_available);

                if ($quantity < 1) {
                    continue;
                }

                $unit = (float) $item->total / max($item->quantity, 1);
                $amount = round($unit * $quantity, 2);
                $itemsTotal += $amount;
                $taxTotal += round(((float) $item->tax_amount / max($item->quantity, 1)) * $quantity, 2);

                $refund->items()->create([
                    'order_item_id' => $item->id,
                    'quantity' => $quantity,
                    'amount' => $amount,
                ]);
            }

            $total = round($itemsTotal + (float) $refund->shipping_amount + (float) $refund->adjustment_amount, 2);
            $total = min($total, $order->refundable_amount);

            $refund->update([
                'items_amount' => $itemsTotal,
                'tax_amount' => $taxTotal,
                'total_amount' => max($total, 0),
                'type' => $total >= (float) $order->grand_total ? 'full' : 'partial',
            ]);

            $order->recordEvent('refund', "Refund {$refund->number} requested", $data['note'] ?? null);

            return $refund;
        });

        Notifier::send(new RefundRequested($refund), $request->user());

        return to_route('admin.refunds.show', $refund)
            ->with('success', "Refund {$refund->number} created.");
    }

    public function show(Refund $refund): Response
    {
        $refund->load(['order.items', 'customer', 'items.orderItem', 'reviewer:id,name', 'cancellation']);

        return Inertia::render('admin/refunds/Show', [
            'refund' => $refund,
            'reasons' => Refund::REASONS,
            'methods' => Refund::METHODS,
        ]);
    }

    public function approve(Request $request, Refund $refund): RedirectResponse
    {
        abort_if($refund->status !== 'pending', 422, 'This refund has already been reviewed.');

        $data = $request->validate([
            'review_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $refund->update([
            'status' => 'approved',
            'review_note' => $data['review_note'] ?? null,
            'reviewed_by' => $request->user()?->id,
            'reviewed_at' => now(),
        ]);

        $refund->order->recordEvent('refund', "Refund {$refund->number} approved", $data['review_note'] ?? null);

        Notifier::send(new RefundDecided($refund->fresh(['items.orderItem'])), $request->user());

        return back()->with('success', "Refund {$refund->number} approved. Process it to release the money.");
    }

    public function reject(Request $request, Refund $refund): RedirectResponse
    {
        abort_if(! in_array($refund->status, ['pending', 'approved'], true), 422, 'This refund can no longer be rejected.');

        $data = $request->validate([
            'review_note' => ['required', 'string', 'max:1000'],
        ]);

        $refund->update([
            'status' => 'rejected',
            'review_note' => $data['review_note'],
            'reviewed_by' => $request->user()?->id,
            'reviewed_at' => now(),
        ]);

        $refund->order->recordEvent('refund', "Refund {$refund->number} rejected", $data['review_note']);

        Notifier::send(new RefundDecided($refund->fresh(['items.orderItem'])), $request->user());

        return back()->with('success', "Refund {$refund->number} rejected.");
    }

    public function process(Request $request, Refund $refund): RedirectResponse
    {
        abort_if($refund->status !== 'approved', 422, 'Only approved refunds can be processed.');

        $data = $request->validate([
            'transaction_reference' => ['nullable', 'string', 'max:120'],
        ]);

        DB::transaction(function () use ($request, $refund, $data) {
            $refund->load('items.orderItem', 'order.items');

            foreach ($refund->items as $line) {
                $item = $line->orderItem;

                $item->increment('quantity_refunded', $line->quantity);

                if ($refund->restock && $item->product_id) {
                    if ($item->product_variant_id) {
                        ProductVariant::where('id', $item->product_variant_id)->increment('stock_quantity', $line->quantity);
                    }

                    Product::where('id', $item->product_id)->increment('stock_quantity', $line->quantity);
                }
            }

            $order = $refund->order;
            $refundedTotal = round((float) $order->refunded_total + (float) $refund->total_amount, 2);
            $isFull = $refundedTotal >= (float) $order->grand_total - 0.01;

            $order->update([
                'refunded_total' => $refundedTotal,
                'payment_status' => $isFull ? 'refunded' : 'partially_refunded',
                'status' => $isFull ? 'refunded' : $order->status,
            ]);

            if ($order->customer) {
                $order->customer->decrement('total_spent', min((float) $refund->total_amount, (float) $order->customer->total_spent));
            }

            $refund->update([
                'status' => 'processed',
                'transaction_reference' => $data['transaction_reference'] ?? null,
                'processed_at' => now(),
                'reviewed_by' => $refund->reviewed_by ?? $request->user()?->id,
            ]);

            $order->recordEvent(
                'refund',
                "Refund {$refund->number} processed",
                'Amount: ₹'.number_format((float) $refund->total_amount, 2),
                ['reference' => $data['transaction_reference'] ?? null],
            );
        });

        // Money actually moving changes what the seller is owed, so this is
        // the one refund event that must never be silent.
        Notifier::send(new RefundDecided($refund->fresh(['items.orderItem'])), $request->user());

        return back()->with('success', "Refund {$refund->number} processed.");
    }
}
