<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Cancellation;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CancellationController extends Controller
{
    public function index(Request $request): Response
    {
        $cancellations = Cancellation::query()
            ->with(['order:id,number,grand_total', 'customer:id,first_name,last_name,email'])
            ->withCount('items')
            ->when($request->string('search')->toString(), fn ($query, $search) => $query->where(
                fn ($q) => $q->where('number', 'like', "%{$search}%")
                    ->orWhereHas('order', fn ($o) => $o->where('number', 'like', "%{$search}%"))
            ))
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->when($request->string('reason')->toString(), fn ($query, $reason) => $query->where('reason', $reason))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('admin/cancellations/Index', [
            'cancellations' => $cancellations,
            'filters' => $request->only(['search', 'status', 'reason']),
            'reasons' => Cancellation::REASONS,
            'counts' => [
                'all' => Cancellation::count(),
                'pending' => Cancellation::where('status', 'pending')->count(),
                'approved' => Cancellation::where('status', 'approved')->count(),
                'rejected' => Cancellation::where('status', 'rejected')->count(),
            ],
            'summary' => [
                'pending_value' => (float) Cancellation::where('status', 'pending')->sum('total_amount'),
                'approved_value' => (float) Cancellation::where('status', 'approved')->sum('total_amount'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $order = Order::with('items')->findOrFail($request->integer('order'));

        return Inertia::render('admin/cancellations/Form', [
            'order' => $order,
            'reasons' => Cancellation::REASONS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'reason' => ['required', Rule::in(array_keys(Cancellation::REASONS))],
            'note' => ['nullable', 'string', 'max:1000'],
            'restock' => ['boolean'],
            'refund_requested' => ['boolean'],
            'requested_by' => ['required', Rule::in(['customer', 'admin', 'vendor', 'system'])],
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'integer', 'exists:order_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $order = Order::with('items')->findOrFail((int) $data['order_id']);

        $cancellation = DB::transaction(function () use ($order, $data) {
            $cancellation = Cancellation::create([
                'number' => Cancellation::nextNumber(),
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'reason' => $data['reason'],
                'note' => $data['note'] ?? null,
                'restock' => $data['restock'] ?? true,
                'refund_requested' => $data['refund_requested'] ?? false,
                'requested_by' => $data['requested_by'],
                'status' => 'pending',
                'scope' => 'partial',
            ]);

            $total = 0;

            foreach ($data['items'] as $row) {
                $item = $order->items->firstWhere('id', $row['order_item_id']);

                if (! $item) {
                    continue;
                }

                $quantity = min($row['quantity'], $item->quantity_available);

                if ($quantity < 1) {
                    continue;
                }

                $amount = round(((float) $item->total / max($item->quantity, 1)) * $quantity, 2);
                $total += $amount;

                $cancellation->items()->create([
                    'order_item_id' => $item->id,
                    'quantity' => $quantity,
                    'amount' => $amount,
                ]);
            }

            $fullOrder = $order->items->sum('quantity') === $cancellation->items()->sum('quantity');

            $cancellation->update([
                'total_amount' => $total,
                'scope' => $fullOrder ? 'full' : 'partial',
            ]);

            $order->recordEvent('cancellation', "Cancellation {$cancellation->number} requested", $data['note'] ?? null);

            return $cancellation;
        });

        return to_route('admin.cancellations.show', $cancellation)
            ->with('success', "Cancellation {$cancellation->number} created.");
    }

    public function show(Cancellation $cancellation): Response
    {
        $cancellation->load([
            'order.items',
            'customer',
            'items.orderItem',
            'reviewer:id,name',
            'refunds',
        ]);

        return Inertia::render('admin/cancellations/Show', [
            'cancellation' => $cancellation,
            'reasons' => Cancellation::REASONS,
        ]);
    }

    public function approve(Request $request, Cancellation $cancellation): RedirectResponse
    {
        abort_if($cancellation->status !== 'pending', 422, 'This request has already been reviewed.');

        $data = $request->validate([
            'review_note' => ['nullable', 'string', 'max:1000'],
            'restock' => ['boolean'],
        ]);

        DB::transaction(function () use ($request, $cancellation, $data) {
            $cancellation->load('items.orderItem', 'order.items');

            foreach ($cancellation->items as $line) {
                $item = $line->orderItem;

                $item->increment('quantity_cancelled', $line->quantity);

                if (($data['restock'] ?? $cancellation->restock) && $item->product_id) {
                    if ($item->product_variant_id) {
                        ProductVariant::where('id', $item->product_variant_id)->increment('stock_quantity', $line->quantity);
                    }

                    Product::where('id', $item->product_id)->increment('stock_quantity', $line->quantity);
                }
            }

            $order = $cancellation->order->fresh('items');
            $allCancelled = $order->items->every(fn ($item) => $item->quantity_cancelled >= $item->quantity);

            $order->update([
                'status' => $allCancelled ? 'cancelled' : $order->status,
                'cancelled_at' => $allCancelled ? now() : $order->cancelled_at,
            ]);

            $cancellation->update([
                'status' => 'approved',
                'restock' => $data['restock'] ?? $cancellation->restock,
                'review_note' => $data['review_note'] ?? null,
                'reviewed_by' => $request->user()?->id,
                'reviewed_at' => now(),
            ]);

            $order->recordEvent('cancellation', "Cancellation {$cancellation->number} approved", $data['review_note'] ?? null);
        });

        return back()->with('success', "Cancellation {$cancellation->number} approved.");
    }

    public function reject(Request $request, Cancellation $cancellation): RedirectResponse
    {
        abort_if($cancellation->status !== 'pending', 422, 'This request has already been reviewed.');

        $data = $request->validate([
            'review_note' => ['required', 'string', 'max:1000'],
        ]);

        $cancellation->update([
            'status' => 'rejected',
            'review_note' => $data['review_note'],
            'reviewed_by' => $request->user()?->id,
            'reviewed_at' => now(),
        ]);

        $cancellation->order->recordEvent('cancellation', "Cancellation {$cancellation->number} rejected", $data['review_note']);

        return back()->with('success', "Cancellation {$cancellation->number} rejected.");
    }
}
