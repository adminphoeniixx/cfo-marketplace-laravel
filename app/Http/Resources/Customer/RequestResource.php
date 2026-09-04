<?php

namespace App\Http\Resources\Customer;

use App\Models\Cancellation;
use App\Models\Refund;
use App\Services\BunnyCdn;
use App\Services\Emoji;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A cancellation or a return, in one shape.
 *
 * The database keeps them apart — different tables, different reason lists,
 * different decisions — but to a shopper they are one queue on the orders
 * screen, so the API says `kind` and hands back the same keys either way.
 *
 * @mixin Cancellation|Refund
 */
class RequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $refund = $this->resource instanceof Refund ? $this->resource : null;
        $cancellation = $this->resource instanceof Cancellation ? $this->resource : null;
        $isRefund = $refund !== null;

        return [
            'number' => $this->number,
            'kind' => $isRefund ? 'refund' : 'cancellation',
            'status' => $this->status,
            'order_number' => $this->whenLoaded('order', fn () => $this->order->number),
            'reason' => $this->reason,
            'reason_label' => ($isRefund ? Refund::REASONS : Cancellation::REASONS)[$this->reason] ?? $this->reason,
            'note' => $this->note,
            'amount' => (float) $this->total_amount,
            'method' => $refund ? (Refund::METHODS[$refund->method] ?? $refund->method) : null,
            'refund_requested' => $isRefund ? true : (bool) $cancellation?->refund_requested,
            // Display-ready, because the refund screen's headline is a
            // sentence about money and a date, not two fields to assemble.
            'eta' => $this->eta($refund, $cancellation),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'name' => $item->orderItem?->name,
                'sku' => $item->orderItem?->sku,
                'image' => BunnyCdn::display($item->orderItem?->image_path),
                'emoji' => Emoji::forProduct($item->orderItem?->name),
                'quantity' => (int) $item->quantity,
                'amount' => (float) $item->amount,
            ])->values()->all()),
            'timeline' => $timeline = $this->timeline($refund),
            // The same steps in the shape the detail screen draws: one
            // `state` per row instead of a `done` flag to combine with
            // position, and `scheduled_at` for the step still to come.
            'events' => self::eventsFrom($timeline),
            'created_at' => $this->created_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'processed_at' => $refund?->processed_at?->toIso8601String(),
            // The same moments named one by one, for a screen that would
            // rather read a field than walk a list. `picked_up_at` is null
            // until couriers report a return collection to the marketplace,
            // which they do not yet.
            'seller_approved_at' => in_array($this->status, ['approved', 'processed'], true)
                ? $this->reviewed_at?->toIso8601String()
                : null,
            'picked_up_at' => null,
            'refund_issued_at' => $refund?->processed_at?->toIso8601String(),
            // A cancellation carries no separate "done" stamp: the moment it
            // was approved is the moment the order was called off.
            'cancelled_at' => $cancellation && in_array($cancellation->status, ['approved', 'processed'], true)
                ? $cancellation->reviewed_at?->toIso8601String()
                : null,
            'withdrawn_at' => $this->status === 'withdrawn'
                ? ($this->reviewed_at ?? $this->updated_at)?->toIso8601String()
                : null,
        ];
    }

    /**
     * The timeline as events: `done` collapsed into a `state`, and the first
     * step nobody has reached marked `current`.
     *
     * @param  list<array<string, mixed>>  $timeline
     * @return list<array<string, mixed>>
     */
    protected static function eventsFrom(array $timeline): array
    {
        $current = null;

        foreach ($timeline as $index => $step) {
            if (! $step['done']) {
                $current = $index;
                break;
            }
        }

        return array_map(fn (array $step, int $index) => [
            'key' => $step['key'],
            'label' => $step['title'],
            'status' => $step['done'] ? 'done' : ($index === $current ? 'current' : 'pending'),
            'state' => $step['done'] ? 'done' : ($index === $current ? 'current' : 'pending'),
            'happened_at' => $step['at'],
            // Nothing is promised: a step with no date behind it has no date
            // in front of it either.
            'scheduled_at' => null,
            'date' => $step['date'],
        ], $timeline, array_keys($timeline));
    }

    /**
     * When the shopper gets their money, in the words the screen shows.
     *
     * Nothing is promised that is not known: a request nobody has looked at
     * yet says how long the step after it takes, rather than naming a date the
     * marketplace cannot hold to.
     */
    protected function eta(?Refund $refund, ?Cancellation $cancellation): ?string
    {
        // Cash on delivery, or a cancellation with nothing taken yet: there is
        // no money on its way back.
        if ($refund === null && ! (bool) $cancellation?->refund_requested) {
            return null;
        }

        if ($refund?->processed_at) {
            return 'Refunded on '.$refund->processed_at->format('j M');
        }

        return match ($this->status) {
            'approved' => 'Back in your account by '
                .($this->reviewed_at ?? $this->created_at ?? now())->copy()->addDays(5)->format('j M'),
            'pending' => 'Usually 3–5 working days once it is approved',
            default => null,
        };
    }

    /**
     * The steps a request goes through, and where this one has got to.
     *
     * Built from its own timestamps rather than from a status machine, so a
     * step is `done` only where something actually happened.
     *
     * @return list<array<string, mixed>>
     */
    protected function timeline(?Refund $refund): array
    {
        $isRefund = $refund !== null;

        $steps = [
            [
                'key' => 'requested',
                'title' => $isRefund ? 'Return requested' : 'Cancellation requested',
                'at' => $this->created_at?->toIso8601String(),
                'date' => $this->created_at?->format('j M'),
                'done' => true,
            ],
            [
                'key' => 'reviewed',
                'title' => match ($this->status) {
                    'rejected' => 'Declined by the seller',
                    'withdrawn' => 'Withdrawn',
                    default => 'Approved by the seller',
                },
                'at' => $this->reviewed_at?->toIso8601String(),
                'date' => $this->reviewed_at?->format('j M'),
                'done' => in_array($this->status, ['approved', 'rejected', 'processed', 'withdrawn'], true),
            ],
        ];

        if ($isRefund) {
            $steps[] = [
                'key' => 'refunded',
                'title' => 'Refunded to your payment method',
                'at' => $refund->processed_at?->toIso8601String(),
                'date' => $refund->processed_at?->format('j M'),
                'done' => $refund->processed_at !== null,
            ];
        }

        return $steps;
    }
}
