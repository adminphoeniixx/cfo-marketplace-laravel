<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Delhivery;
use Illuminate\Console\Command;

/**
 * Ask Delhivery where every parcel in flight has got to.
 *
 * The tracking screen was drawing the order's own timestamps, which meant "Out
 * for delivery" could only ever be reached retrospectively — the marketplace
 * simply did not know until the parcel arrived. This is what closes that: each
 * scan becomes an event on the order, and the two timestamps a shopper
 * actually reads, `shipped_at` and `delivered_at`, come from the courier
 * rather than from a seller pressing a button.
 */
class SyncShipments extends Command
{
    protected $signature = 'shipments:sync {--limit=200 : How many orders to ask about in one run}';

    protected $description = 'Update in-flight orders from the courier’s own scans';

    public function handle(): int
    {
        if (! Delhivery::enabled()) {
            $this->components->warn('Delhivery is not configured; nothing to sync.');

            return self::SUCCESS;
        }

        $orders = Order::query()
            ->whereNotNull('tracking_number')
            ->whereRaw('lower(carrier) = ?', ['delhivery'])
            // Anything still moving. A delivered or called-off parcel has
            // nothing left to tell us, and asking anyway is a request per
            // order per run, for ever.
            ->whereNull('delivered_at')
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->limit((int) $this->option('limit'))
            ->get();

        $updated = 0;

        foreach ($orders as $order) {
            $tracking = Delhivery::track((string) $order->tracking_number);

            if ($tracking === null) {
                // Unreachable is not "nothing happened", and must never be
                // written down as if it were.
                continue;
            }

            $updated += $this->apply($order, $tracking) ? 1 : 0;
        }

        $this->components->info("Checked {$orders->count()} parcel(s), updated {$updated}.");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $tracking
     */
    protected function apply(Order $order, array $tracking): bool
    {
        $status = (string) $tracking['status'];
        $changed = false;

        // The last scan we have already written down, so a run every ten
        // minutes does not put the same line on the timeline six times an hour.
        $seen = $order->events()
            ->where('type', 'shipment')
            ->latest('id')
            ->first()?->meta['scan_at'] ?? null;

        foreach ((array) $tracking['scans'] as $scan) {
            $at = $scan['at'] ?? null;

            if (! is_string($at) || ($seen !== null && $at <= $seen)) {
                continue;
            }

            $order->recordEvent(
                'shipment',
                (string) ($scan['status'] ?? 'Update from the courier'),
                trim(((string) ($scan['instructions'] ?? '')).' '.($scan['location'] ? '· '.$scan['location'] : '')) ?: null,
                ['carrier' => 'delhivery', 'scan_at' => $at, 'source' => 'delhivery-api'],
            );

            $changed = true;
        }

        $fields = [];

        if ($order->shipped_at === null && in_array($status, ['in_transit', 'out_for_delivery', 'delivered'], true)) {
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

        if ($fields !== []) {
            $order->forceFill($fields)->save();
            $changed = true;
        }

        return $changed;
    }
}
