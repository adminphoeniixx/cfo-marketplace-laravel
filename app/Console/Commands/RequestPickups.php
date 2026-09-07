<?php

namespace App\Console\Commands;

use App\Actions\Shipping\SchedulePickup;
use App\Models\DeliveryPartner;
use App\Models\Order;
use Illuminate\Console\Command;

/**
 * One van per courier per day.
 *
 * Every parcel booked since the last run and not yet collected, grouped by the
 * courier carrying it, asked for in a single request each. Both providers
 * charge for a pickup and rate-limit the endpoint, so a request per order
 * would be both expensive and refused.
 *
 * Runs in the morning because a collection asked for after the van has been
 * round is a collection tomorrow, and a seller who packed something at nine
 * should not lose the day.
 */
class RequestPickups extends Command
{
    protected $signature = 'shipments:pickup
        {--carrier= : Only this courier, by name}
        {--date= : The collection date, Y-m-d; the courier’s own default otherwise}';

    protected $description = 'Ask each courier to collect the parcels waiting for it';

    public function handle(SchedulePickup $schedule): int
    {
        $carriers = DeliveryPartner::query()
            ->whereNotNull('driver')
            ->where('is_active', true)
            ->when($this->option('carrier'), fn ($query, $name) => $query->whereRaw('lower(name) = ?', [mb_strtolower((string) $name)]))
            ->pluck('name');

        if ($carriers->isEmpty()) {
            $this->components->warn('No courier is connected; nothing to collect.');

            return self::SUCCESS;
        }

        foreach ($carriers as $carrier) {
            $orders = Order::query()
                ->whereRaw('lower(carrier) = ?', [mb_strtolower($carrier)])
                ->whereNotNull('tracking_number')
                // Not yet asked for. A parcel whose pickup was booked and then
                // missed is the courier's problem to re-attempt, not a reason
                // to book — and pay for — a second collection.
                ->whereNull('pickup_scheduled_at')
                /*
                | Still on the seller's table. `booked` is what a courier calls
                | a parcel it has manifested and not collected; a null status is
                | one booked before the last sync had anything to say about it,
                | and is the common case for anything packed this morning.
                */
                ->where(fn ($query) => $query->whereNull('shipment_status')->orWhere('shipment_status', 'booked'))
                ->whereNull('shipped_at')
                ->whereNotIn('status', ['cancelled', 'refunded'])
                ->get();

            if ($orders->isEmpty()) {
                $this->components->info("{$carrier}: nothing waiting.");

                continue;
            }

            $result = $schedule->handle($carrier, $orders, $this->option('date'));

            $result['scheduled']
                ? $this->components->info("{$carrier}: {$result['orders']} parcel(s) booked for collection."
                    .($result['reference'] ? " Reference {$result['reference']}." : ''))
                : $this->components->warn("{$carrier}: ".($result['message'] ?: 'the collection was refused.'));
        }

        return self::SUCCESS;
    }
}
