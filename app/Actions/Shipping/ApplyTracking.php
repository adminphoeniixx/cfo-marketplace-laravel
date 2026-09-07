<?php

namespace App\Actions\Shipping;

use App\Models\Order;
use App\Notifications\ParcelNeedsAttention;
use App\Services\Notifier;

/**
 * One courier's account of a parcel, written onto the order.
 *
 * This exists as its own class because there are now two ways the news
 * arrives — the fifteen-minute sync, and a webhook the courier pushes within
 * seconds — and they must not disagree. A parcel whose delivery is recorded by
 * a webhook and then re-read by the next sync should end up in exactly the
 * state it was already in, having written nothing twice.
 *
 * That is what makes every write here conditional on what is already there.
 * `shipped_at` is set only if unset, scans older than the newest one already
 * recorded are dropped, and a failed attempt counts once because it is counted
 * from the scan list rather than incremented.
 */
class ApplyTracking
{
    /**
     * What a courier says when a delivery was tried and did not happen.
     *
     * Couriers agree on almost nothing here — Shiprocket has a status for it,
     * Delhivery buries it in the instructions on an otherwise ordinary "In
     * Transit" scan — so this matches the phrases both of them actually use.
     * Being generous is the right failure: an attempt counted that was not one
     * puts a line on a timeline, where an attempt missed loses the parcel.
     */
    private const FAILED_ATTEMPT = [
        'undelivered', 'not delivered', 'delivery attempted', 'attempted delivery',
        'consignee unavailable', 'consignee not available', 'customer unavailable',
        'customer not available', 'refused', 'address incorrect', 'incorrect address',
        'premises closed', 'office closed', 'ndr',
    ];

    /**
     * The states a parcel can be in that somebody has to do something about.
     *
     * Not failures of the courier — a shopper who was out is nobody's fault —
     * but none of them resolve themselves by waiting, which is the difference
     * that matters.
     */
    private const NEEDS_ATTENTION = ['undelivered', 'returning', 'returned', 'lost'];

    /**
     * @param  array{status: string, raw_status?: string, location?: string|null, updated_at?: string|null, scans?: list<array<string, mixed>>}  $tracking
     * @return bool Whether anything about the order actually changed.
     */
    public function handle(Order $order, array $tracking): bool
    {
        $status = (string) $tracking['status'];
        $was = $order->shipment_status;

        $changed = $this->recordScans($order, (array) ($tracking['scans'] ?? []));
        $fields = $this->fieldsFor($order, $status);

        $attempts = $this->attemptsIn((array) ($tracking['scans'] ?? []));

        // Counted rather than incremented, so the same scan arriving twice —
        // once by webhook, once by the next sync — is still one attempt.
        if ($attempts > (int) $order->delivery_attempts) {
            $fields['delivery_attempts'] = $attempts;
        }

        if ($status !== $was) {
            $fields['shipment_status'] = $status;
        }

        if ($fields !== []) {
            $order->forceFill($fields)->save();
            $changed = true;
        }

        // Only on the way *into* one of these states. A parcel that is still
        // returning an hour later is the same news, and the seller has already
        // had it.
        if ($status !== $was && in_array($status, self::NEEDS_ATTENTION, true)) {
            $this->announce($order->fresh('items'), $status, (string) ($tracking['raw_status'] ?? $status));
        }

        return $changed;
    }

    /**
     * Every scan the courier has that the order does not, as timeline events.
     *
     * @param  list<array<string, mixed>>  $scans
     */
    protected function recordScans(Order $order, array $scans): bool
    {
        /*
        | The newest scan already written down, so a run every fifteen minutes
        | does not put the same line on the timeline four times an hour.
        |
        | The *newest carrying a scan time*, not simply the newest: a booking,
        | a cancellation and the alert `announce()` raises are all `shipment`
        | events with no scan behind them, and reading one of those as "nothing
        | recorded yet" would re-write the parcel's whole history on the very
        | next run.
        */
        $seen = $order->events()
            ->where('type', 'shipment')
            ->latest('id')
            ->limit(100)
            ->get()
            ->pluck('meta.scan_at')
            ->filter()
            ->max();

        $changed = false;

        foreach ($scans as $scan) {
            $at = $scan['at'] ?? null;

            if (! is_string($at) || ($seen !== null && $at <= $seen)) {
                continue;
            }

            $order->recordEvent(
                'shipment',
                (string) ($scan['status'] ?? 'Update from the courier'),
                trim(((string) ($scan['instructions'] ?? '')).' '.($scan['location'] ? '· '.$scan['location'] : '')) ?: null,
                ['carrier' => $order->carrier, 'scan_at' => $at, 'source' => 'courier-api'],
            );

            $changed = true;
        }

        return $changed;
    }

    /**
     * What this status means for the order itself.
     *
     * The order's own `status` is deliberately conservative: a parcel coming
     * back does not cancel a sale, and does not refund one either. Somebody
     * decides that, having seen the alert this raises.
     *
     * @return array<string, mixed>
     */
    protected function fieldsFor(Order $order, string $status): array
    {
        $fields = [];

        $moving = ['in_transit', 'out_for_delivery', 'delivered', 'undelivered', 'returning', 'returned'];

        if ($order->shipped_at === null && in_array($status, $moving, true)) {
            $fields['shipped_at'] = now();
            $fields['status'] = $order->status === 'processing' ? 'shipped' : $order->status;
        }

        if ($status === 'delivered' && $order->delivered_at === null) {
            $fields['delivered_at'] = now();
            $fields['status'] = 'completed';
            $fields['fulfillment_status'] = 'fulfilled';

            // Cash collected at the door is the moment a COD order is paid,
            // and nothing else was ever going to tell the marketplace that.
            if ($order->isPayOnDelivery() && $order->payment_status !== 'paid') {
                $fields['payment_status'] = 'paid';
                $fields['paid_at'] = now();
            }
        }

        if ($status === 'returned' && $order->returned_at === null) {
            $fields['returned_at'] = now();
            // The goods are back, so the seller no longer owes them — but the
            // money is a separate conversation and this does not have it.
            $fields['fulfillment_status'] = 'unfulfilled';
        }

        return $fields;
    }

    /**
     * How many times delivery has been tried and failed, per the scan list.
     *
     * @param  list<array<string, mixed>>  $scans
     */
    protected function attemptsIn(array $scans): int
    {
        $attempts = 0;

        foreach ($scans as $scan) {
            $text = mb_strtolower(trim(
                ((string) ($scan['status'] ?? '')).' '.((string) ($scan['instructions'] ?? ''))
            ));

            foreach (self::FAILED_ATTEMPT as $phrase) {
                if (str_contains($text, $phrase)) {
                    $attempts++;

                    // One scan is one attempt, however many phrases it matches.
                    break;
                }
            }
        }

        return $attempts;
    }

    /**
     * Tell whoever has to act — which for a parcel is the seller who packed it.
     */
    protected function announce(Order $order, string $status, string $raw): void
    {
        $order->recordEvent(
            'shipment',
            match ($status) {
                'undelivered' => 'Delivery attempt failed',
                'returning' => 'Parcel is coming back',
                'returned' => 'Parcel is back with the seller',
                default => 'Courier has lost this parcel',
            },
            $raw !== '' ? 'The courier reports: '.$raw : null,
            ['carrier' => $order->carrier, 'shipment_status' => $status, 'source' => 'courier-api'],
        );

        Notifier::send(new ParcelNeedsAttention($order, $status));
    }
}
