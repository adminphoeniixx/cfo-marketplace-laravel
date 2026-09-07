<?php

namespace App\Actions\Shipping;

use App\Models\Order;
use App\Services\Couriers\Couriers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Asking a courier to actually come and collect.
 *
 * Booking a waybill is not a pickup. Delhivery and Shiprocket will both take a
 * booking, print a label and then wait to be asked separately for a van — so
 * without this a marketplace could have a week of parcels manifested, labelled
 * and sitting on a table.
 *
 * One request per courier per day, not per parcel: that is the shape both
 * providers actually want, and it is also the honest one — a van comes to a
 * warehouse, not to an order.
 */
class SchedulePickup
{
    /**
     * Book one collection for a courier, covering every parcel given.
     *
     * @param  Collection<int, Order>  $orders  Parcels booked and waiting.
     * @return array{scheduled: bool, reference: string|null, message: string|null, orders: int}
     */
    public function handle(string $carrier, Collection $orders, ?string $date = null): array
    {
        $orders = $orders->filter(fn (Order $order) => filled($order->tracking_number));

        if ($orders->isEmpty()) {
            return ['scheduled' => false, 'reference' => null, 'message' => 'No booked parcels to collect.', 'orders' => 0];
        }

        $courier = Couriers::forCarrier($carrier);

        if ($courier === null) {
            return ['scheduled' => false, 'reference' => null, 'message' => "No client is connected for {$carrier}.", 'orders' => 0];
        }

        $waybills = $orders->pluck('tracking_number')->map(fn ($w) => (string) $w)->values()->all();
        $result = $courier->schedulePickup($waybills, $date);

        if ($result === null) {
            return ['scheduled' => false, 'reference' => null, 'message' => "{$carrier} could not be reached.", 'orders' => 0];
        }

        if (! $result['scheduled']) {
            return [...$result, 'orders' => 0];
        }

        $when = $date ? Carbon::parse($date) : now();

        foreach ($orders as $order) {
            $order->forceFill(['pickup_scheduled_at' => $when])->save();

            $order->recordEvent(
                'shipment',
                'Pickup booked with '.$carrier,
                $result['reference']
                    ? 'Collection reference '.$result['reference']
                    : 'The courier is coming for this parcel.',
                [
                    'carrier' => $carrier,
                    'pickup_reference' => $result['reference'],
                    'source' => 'courier-api',
                ],
            );
        }

        return [...$result, 'orders' => $orders->count()];
    }
}
