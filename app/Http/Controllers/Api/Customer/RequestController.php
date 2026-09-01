<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\RequestResource;
use App\Models\Cancellation;
use App\Models\Order;
use App\Models\Refund;
use App\Notifications\CancellationRequested;
use App\Notifications\RefundRequested;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Calling off an order, and sending something back.
 *
 * Two tables behind one screen: to the shopper these are one queue of "things
 * I asked for", so the list merges them and each carries a `kind`. What a
 * shopper may raise is decided here — before it shipped it is a cancellation,
 * after it arrived it is a return, and there is no third case.
 */
class RequestController extends Controller
{
    use ScopesToCustomer;

    /**
     * Both kinds, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $cancellations = $this->customerCancellations($request)->get();
        $refunds = $this->customerRefunds($request)->get();

        $merged = $cancellations->concat($refunds)
            ->sortByDesc('created_at')
            ->values();

        return response()->json(['data' => RequestResource::collection($merged)]);
    }

    public function show(Request $request, string $number): JsonResponse
    {
        $model = str_starts_with(mb_strtoupper($number), 'REF')
            ? $this->customerRefunds($request)->where('number', $number)->firstOrFail()
            : $this->customerCancellations($request)->where('number', $number)->firstOrFail();

        return response()->json(['data' => new RequestResource($model)]);
    }

    /**
     * Ask for an order — or part of one — to be called off.
     */
    public function storeCancellation(Request $request, string $order): JsonResponse
    {
        $model = $this->findOwnedOrder($request, $order);

        if (! $model->canBeCancelledByCustomer()) {
            throw ValidationException::withMessages([
                'order' => 'This order has already been packed. Ask for a return once it arrives.',
            ]);
        }

        $data = $request->validate([
            'reason' => ['required', Rule::in(array_keys(Cancellation::REASONS))],
            'note' => ['nullable', 'string', 'max:1000'],
            'items' => ['nullable', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        // No items named means the whole order, which is what the app sends
        // when the shopper taps Cancel from the list.
        $rows = $data['items'] ?? $model->items->map(fn ($item) => [
            'order_item_id' => $item->id,
            'quantity' => $item->quantity_available,
        ])->all();

        $cancellation = DB::transaction(function () use ($model, $data, $rows) {
            $cancellation = Cancellation::create([
                'number' => Cancellation::nextNumber(),
                'order_id' => $model->id,
                'customer_id' => $model->customer_id,
                'reason' => $data['reason'],
                'note' => $data['note'] ?? null,
                'restock' => true,
                // Money already taken has to come back; cash on delivery has
                // nothing to refund.
                'refund_requested' => $model->payment_status === 'paid',
                'requested_by' => 'customer',
                'status' => 'pending',
                'scope' => 'partial',
            ]);

            $total = $this->attachItems($cancellation, $model, $rows);
            $whole = (int) $cancellation->items()->sum('quantity') === (int) $model->items->sum('quantity_available');

            $cancellation->update([
                'total_amount' => $total,
                'scope' => $whole ? 'full' : 'partial',
            ]);

            $model->recordEvent(
                'cancellation',
                "Cancellation {$cancellation->number} requested",
                $data['note'] ?? null,
                ['source' => 'customer-api'],
            );

            return $cancellation;
        });

        Notifier::send(new CancellationRequested($cancellation));

        return response()->json([
            'data' => new RequestResource($cancellation->load(['order:id,number', 'items.orderItem:id,name,sku'])),
        ], 201);
    }

    /**
     * Send something back after it arrived.
     */
    public function storeRefund(Request $request, string $order): JsonResponse
    {
        $model = $this->findOwnedOrder($request, $order);

        if (! $model->canBeReturnedByCustomer()) {
            throw ValidationException::withMessages([
                'order' => 'The '.Order::RETURN_WINDOW_DAYS.'-day return window for this order has closed.',
            ]);
        }

        $data = $request->validate([
            'reason' => ['required', Rule::in(array_keys(Refund::REASONS))],
            'method' => ['required', Rule::in(array_keys(Refund::METHODS))],
            'note' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $refund = DB::transaction(function () use ($model, $data) {
            $refund = Refund::create([
                'number' => Refund::nextNumber(),
                'order_id' => $model->id,
                'customer_id' => $model->customer_id,
                'reason' => $data['reason'],
                'method' => $data['method'],
                'note' => $data['note'] ?? null,
                'restock' => true,
                'shipping_amount' => 0,
                'adjustment_amount' => 0,
                'status' => 'pending',
            ]);

            $total = $this->attachItems($refund, $model, $data['items']);

            $refund->update([
                'items_amount' => $total,
                // Never more than the order can still give back, whatever the
                // client asked for.
                'total_amount' => min($total, $model->refundable_amount),
            ]);

            $model->recordEvent(
                'refund',
                "Return {$refund->number} requested",
                $data['note'] ?? null,
                ['source' => 'customer-api'],
            );

            return $refund;
        });

        Notifier::send(new RefundRequested($refund));

        return response()->json([
            'data' => new RequestResource($refund->load(['order:id,number', 'items.orderItem:id,name,sku'])),
        ], 201);
    }

    /**
     * Take a request back, while nobody has acted on it yet.
     */
    public function withdraw(Request $request, string $number): JsonResponse
    {
        $model = str_starts_with(mb_strtoupper($number), 'REF')
            ? $this->customerRefunds($request)->where('number', $number)->firstOrFail()
            : $this->customerCancellations($request)->where('number', $number)->firstOrFail();

        if ($model->status !== 'pending') {
            throw ValidationException::withMessages([
                'number' => 'This request has already been answered.',
            ]);
        }

        $model->update(['status' => 'withdrawn']);

        return response()->json(['withdrawn' => true]);
    }

    /**
     * Copy the chosen order lines onto the request, priced pro rata.
     *
     * Quantities are clamped to what is still live on the line, so asking to
     * cancel three of a pair returns two rather than an error.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    protected function attachItems(Cancellation|Refund $model, Order $order, array $rows): float
    {
        $total = 0.0;

        foreach ($rows as $row) {
            $item = $order->items->firstWhere('id', (int) $row['order_item_id']);

            if (! $item) {
                continue;
            }

            $quantity = min((int) $row['quantity'], (int) $item->quantity_available);

            if ($quantity < 1) {
                continue;
            }

            $amount = round(((float) $item->total / max($item->quantity, 1)) * $quantity, 2);
            $total += $amount;

            $model->items()->create([
                'order_item_id' => $item->id,
                'quantity' => $quantity,
                'amount' => $amount,
            ]);
        }

        if ($total <= 0) {
            throw ValidationException::withMessages([
                'items' => 'None of those items can be cancelled or returned.',
            ]);
        }

        return round($total, 2);
    }
}
