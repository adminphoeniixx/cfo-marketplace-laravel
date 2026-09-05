<?php

namespace App\Actions\Shipping;

use App\Models\Order;
use App\Services\Couriers\Couriers;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Getting a waybill without anybody typing one.
 *
 * Called after a seller marks items fulfilled. Which courier — and whether it
 * can be spoken to at all — comes from the partner row the order's carrier
 * name resolves to; a partner that is only a name and a tracking link books
 * nothing, and that is not a failure.
 *
 * Everything about it is deliberately quiet: a courier refusing a booking must
 * not undo a fulfilment the seller has already done, so a failure is recorded
 * on the order and the seller can still type a waybill in by hand.
 */
class BookShipment
{
    /**
     * Book with the carrier if this order wants it, and say what happened.
     *
     * @return string|null The waybill, where one was booked.
     */
    public function handle(Order $order): ?string
    {
        if (! $this->shouldBook($order)) {
            return null;
        }

        $courier = Couriers::forCarrier($order->carrier);

        if ($courier === null) {
            // A courier with no client, or none configured: the seller types a
            // waybill in by hand, exactly as before any of this existed.
            return null;
        }

        try {
            $waybill = $courier->createShipment($order->loadMissing('items'));
        } catch (RuntimeException $e) {
            // Recorded rather than raised: the parcel still exists, the seller
            // can still hand it over, and an exception here would roll back a
            // fulfilment that has nothing wrong with it.
            $order->recordEvent(
                'shipment',
                'Could not book with '.$order->carrier,
                $e->getMessage(),
                ['carrier' => $order->carrier, 'source' => 'courier-api'],
            );

            Log::warning('Courier booking failed.', [
                'order' => $order->number,
                'carrier' => $order->carrier,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $order->forceFill(['tracking_number' => $waybill])->save();

        $order->recordEvent(
            'shipment',
            'Booked with '.$order->carrier,
            "Waybill {$waybill}",
            ['carrier' => $order->carrier, 'waybill' => $waybill, 'source' => 'courier-api'],
        );

        return $waybill;
    }

    /**
     * Only where there is something to book and nothing booked already.
     *
     * A waybill the seller typed in is left alone: they know something we do
     * not — a parcel handed over at a counter, say — and booking a second one
     * would put two labels on one box.
     */
    protected function shouldBook(Order $order): bool
    {
        return filled($order->carrier)
            && blank($order->tracking_number)
            && ! in_array($order->status, ['cancelled', 'refunded'], true);
    }
}
