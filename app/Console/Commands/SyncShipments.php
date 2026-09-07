<?php

namespace App\Console\Commands;

use App\Actions\Shipping\ApplyTracking;
use App\Models\DeliveryPartner;
use App\Models\Order;
use App\Services\Couriers\Couriers;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Ask every courier where the parcels in flight have got to.
 *
 * The tracking screen was drawing the order's own timestamps, which meant "Out
 * for delivery" could only ever be reached retrospectively — the marketplace
 * simply did not know until the parcel arrived. This is what closes that: each
 * scan becomes an event on the order, and the two timestamps a shopper
 * actually reads, `shipped_at` and `delivered_at`, come from the courier
 * rather than from a seller pressing a button.
 *
 * Webhooks now carry the same news faster, and this stays anyway — it is what
 * catches a parcel whose webhook was missed, or a courier that never sends
 * one. The work of writing it down belongs to `ApplyTracking`, so both routes
 * reach the same state.
 */
class SyncShipments extends Command
{
    protected $signature = 'shipments:sync {--limit=200 : How many orders to ask about in one run}';

    protected $description = 'Update in-flight orders from the courier’s own scans';

    public function handle(ApplyTracking $apply): int
    {
        // Every courier this marketplace can actually speak to. A partner
        // that is only a name and a link has nothing to be asked.
        $carriers = DeliveryPartner::query()
            ->whereNotNull('driver')
            ->where('is_active', true)
            ->pluck('name')
            ->map(fn (string $name) => mb_strtolower($name))
            ->all();

        if ($carriers === []) {
            $this->components->warn('No courier is connected; nothing to sync.');

            return self::SUCCESS;
        }

        $orders = Order::query()
            ->whereNotNull('tracking_number')
            ->whereIn(DB::raw('lower(carrier)'), $carriers)
            /*
            | Anything still moving. Delivered, returned, called-off and lost
            | parcels have nothing left to tell us, and asking anyway is a
            | request per order per run, for ever.
            |
            | `returning` is deliberately *not* here: a parcel on its way back
            | is still moving, and the marketplace wants to know when it lands.
            */
            ->whereNull('delivered_at')
            ->whereNull('returned_at')
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->where(fn ($query) => $query
                ->whereNull('shipment_status')
                ->orWhereNotIn('shipment_status', ['returned', 'cancelled', 'lost']))
            ->limit((int) $this->option('limit'))
            ->get();

        $updated = 0;

        foreach ($orders as $order) {
            $courier = Couriers::forCarrier($order->carrier);
            $tracking = $courier?->track((string) $order->tracking_number);

            if ($tracking === null) {
                // Unreachable is not "nothing happened", and must never be
                // written down as if it were.
                continue;
            }

            $updated += $apply->handle($order, $tracking) ? 1 : 0;
        }

        $this->components->info("Checked {$orders->count()} parcel(s), updated {$updated}.");

        return self::SUCCESS;
    }
}
