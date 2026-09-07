<?php

namespace App\Actions\Shipping;

use App\Models\Order;
use App\Services\Couriers\Couriers;
use Illuminate\Support\Facades\Log;

/**
 * Telling the courier that a cancelled order is not coming.
 *
 * Without this, cancelling an order cancelled it here and nowhere else. The
 * waybill stayed live, the van still came, and — on a cash-on-delivery
 * parcel — somebody could still be asked for money at a door for an order the
 * marketplace considered dead. Every courier bills for that.
 *
 * Deliberately quiet, for the same reason `BookShipment` is: a courier
 * refusing must not undo a cancellation the shopper has already been told
 * about. It is written down and left for a human, because the alternative is
 * an order that cannot be cancelled while a courier is having a bad morning.
 */
class CancelShipment
{
    /**
     * Call the booking off if there is one. True where the courier agreed.
     */
    public function handle(Order $order): bool
    {
        if (blank($order->tracking_number) || $order->shipment_status === 'cancelled') {
            return false;
        }

        // Already collected, already delivered, already coming back: none of
        // these can be called off, and asking earns a refusal that reads like
        // a fault.
        if (in_array($order->shipment_status, ['delivered', 'returning', 'returned', 'lost'], true)) {
            return false;
        }

        $courier = Couriers::forCarrier($order->carrier);

        if ($courier === null) {
            // A waybill somebody typed in by hand, or a courier with no
            // client. Neither is something this marketplace can cancel, and
            // the seller has to ring them — which is worth saying on the
            // timeline rather than leaving to be discovered.
            $order->recordEvent(
                'shipment',
                'Cancel this booking with '.($order->carrier ?: 'the courier').' by hand',
                'The marketplace cannot call off a booking it did not make.',
                ['carrier' => $order->carrier, 'waybill' => $order->tracking_number, 'source' => 'marketplace'],
            );

            return false;
        }

        $cancelled = $courier->cancel((string) $order->tracking_number);

        if (! $cancelled) {
            $order->recordEvent(
                'shipment',
                'Could not cancel the booking with '.$order->carrier,
                'The courier refused or could not be reached. Cancel waybill '
                    .$order->tracking_number.' with them directly.',
                ['carrier' => $order->carrier, 'waybill' => $order->tracking_number, 'source' => 'courier-api'],
            );

            Log::warning('A courier would not cancel a booking.', [
                'order' => $order->number,
                'carrier' => $order->carrier,
                'waybill' => $order->tracking_number,
            ]);

            return false;
        }

        // The waybill stays on the order. It is what the courier's own records
        // are keyed by, and somebody reconciling a bill later will need it —
        // `shipment_status` is what says it is dead.
        $order->forceFill([
            'shipment_status' => 'cancelled',
            'pickup_scheduled_at' => null,
        ])->save();

        $order->recordEvent(
            'shipment',
            'Booking cancelled with '.$order->carrier,
            "Waybill {$order->tracking_number} is no longer live.",
            ['carrier' => $order->carrier, 'waybill' => $order->tracking_number, 'source' => 'courier-api'],
        );

        return true;
    }
}
