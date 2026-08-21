<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Http\Resources\Seller\CancellationResource;
use App\Models\Cancellation;
use App\Models\CancellationItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Notifications\CancellationRequested;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A seller may raise a cancellation and reply to one, but never decide it.
 * Approving or rejecting stays the marketplace's call — a seller states their
 * case, staff decide.
 */
class CancellationController extends Controller
{
    use ScopesToStore;

    public function index(Request $request): AnonymousResourceCollection
    {
        $cancellations = $this->storeCancellations($request)
            ->when($request->string('status')->toString(), fn ($query, $status) => $query
                ->where('status', $status))
            ->latest('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return CancellationResource::collection($cancellations);
    }

    public function show(Request $request, int $cancellation): CancellationResource
    {
        return new CancellationResource(
            $this->storeCancellations($request)->findOrFail($cancellation)
        );
    }

    /**
     * Raise a cancellation for this store's own lines.
     *
     * The usual case is stock that turned out not to exist. It lands as
     * `pending` with `requested_by` fixed to `vendor` — a seller stating what
     * they cannot ship, not deciding the outcome.
     */
    public function store(Request $request): JsonResponse
    {
        $storeId = $this->storeId($request);

        $data = $request->validate([
            'order_id' => ['required', 'integer'],
            'reason' => ['required', Rule::in(array_keys(Cancellation::REASONS))],
            'note' => ['nullable', 'string', 'max:1000'],
            'restock' => ['nullable', 'boolean'],
            'refund_requested' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        // Scoped lookup: another store's order is a 404, not a 403 — the
        // seller has no business knowing it exists.
        $order = $this->findOwnedOrder($request, (int) $data['order_id']);

        if (in_array($order->status, ['cancelled', 'refunded'], true)) {
            throw ValidationException::withMessages([
                'order_id' => 'There is nothing left to cancel on this order.',
            ]);
        }

        $lines = $this->cancellableLines($order, $storeId, $data['items']);

        // Defaults that follow from the situation, overridable by the app:
        // stock that never existed must not be handed back to stock, and a
        // paid order that is cancelled owes the buyer money.
        $restock = $data['restock'] ?? ($data['reason'] !== 'out_of_stock');
        $refundRequested = $data['refund_requested'] ?? ($order->payment_status === 'paid');

        $cancellation = DB::transaction(function () use ($order, $data, $lines, $restock, $refundRequested, $request) {
            $cancellation = Cancellation::create([
                'number' => Cancellation::nextNumber(),
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'reason' => $data['reason'],
                'note' => $data['note'] ?? null,
                'restock' => $restock,
                'refund_requested' => $refundRequested,
                // Never read from the payload: a seller can only ever speak
                // as themselves.
                'requested_by' => 'vendor',
                'status' => 'pending',
                'scope' => 'partial',
            ]);

            $total = 0.0;

            foreach ($lines as ['item' => $item, 'quantity' => $quantity]) {
                $amount = round(((float) $item->total / max($item->quantity, 1)) * $quantity, 2);
                $total += $amount;

                $cancellation->items()->create([
                    'order_item_id' => $item->id,
                    'quantity' => $quantity,
                    'amount' => $amount,
                ]);
            }

            // `full` only when nothing at all is left on the order, which on a
            // shared basket means the other seller's lines have to be gone too.
            $outstanding = $order->items()->get()
                ->sum(fn (OrderItem $item) => $item->quantity_available);

            $cancellation->update([
                'total_amount' => round($total, 2),
                'scope' => $outstanding <= collect($lines)->sum('quantity') ? 'full' : 'partial',
            ]);

            $order->recordEvent(
                'cancellation',
                "Cancellation {$cancellation->number} requested by ".$request->user()->vendor->name,
                $data['note'] ?? null,
                ['vendor_id' => $this->storeId($request), 'source' => 'seller-api'],
            );

            return $cancellation;
        });

        // The seller raised it, so they are not in their own audience.
        Notifier::send(new CancellationRequested($cancellation->load('order.items')), $request->user());

        return response()->json([
            'data' => new CancellationResource(
                $this->storeCancellations($request)->findOrFail($cancellation->id)
            ),
        ], 201);
    }

    /**
     * Work out how much of each requested line may actually be cancelled.
     *
     * Three things eat into it: quantity already cancelled or refunded,
     * quantity already shipped — that is a return, not a cancellation — and
     * quantity sitting in another cancellation still awaiting review, which is
     * what stops a seller raising the same line twice.
     *
     * @param  array<int, array{order_item_id: int, quantity: int}>  $requested
     * @return list<array{item: OrderItem, quantity: int}>
     */
    private function cancellableLines(Order $order, int $storeId, array $requested): array
    {
        // Loaded from the order so a line belonging to another store simply is
        // not there to be found.
        $mine = $order->items()->where('vendor_id', $storeId)->get()->keyBy('id');

        $pending = CancellationItem::query()
            ->whereIn('order_item_id', $mine->keys())
            ->whereHas('cancellation', fn ($query) => $query->where('status', 'pending'))
            ->selectRaw('order_item_id, sum(quantity) as total')
            ->groupBy('order_item_id')
            ->pluck('total', 'order_item_id');

        $lines = [];

        foreach ($requested as $index => $row) {
            $item = $mine->get((int) $row['order_item_id']);

            if (! $item) {
                throw ValidationException::withMessages([
                    "items.{$index}.order_item_id" => 'That item is not on this order, or is not yours.',
                ]);
            }

            $available = $item->quantity_available
                - $item->quantity_fulfilled
                - (int) ($pending[$item->id] ?? 0);

            if ($row['quantity'] > $available) {
                throw ValidationException::withMessages([
                    "items.{$index}.quantity" => $available <= 0
                        ? "Nothing is left to cancel on {$item->name}."
                        : "Only {$available} of {$item->name} can still be cancelled.",
                ]);
            }

            $lines[] = ['item' => $item, 'quantity' => (int) $row['quantity']];
        }

        return $lines;
    }

    /**
     * Add the seller's side of the story to the order timeline.
     */
    public function respond(Request $request, int $cancellation): JsonResponse
    {
        $model = $this->storeCancellations($request)->findOrFail($cancellation);

        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
        ]);

        $model->order->recordEvent(
            'note',
            "Seller response to cancellation {$model->number}",
            $data['note'],
            [
                'vendor_id' => $this->storeId($request),
                'cancellation_id' => $model->id,
                'source' => 'seller-api',
            ],
        );

        return response()->json(['message' => 'Response recorded.'], 201);
    }
}
