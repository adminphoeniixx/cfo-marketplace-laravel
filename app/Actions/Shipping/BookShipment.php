<?php

namespace App\Actions\Shipping;

use App\Models\Order;
use App\Services\Delhivery;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Getting a waybill without anybody typing one.
 *
 * Called after a seller marks items fulfilled. Everything about it is
 * deliberately quiet: a courier refusing a booking must not undo a fulfilment
 * the seller has already done, so a failure is recorded on the order and the
 * seller can still type a waybill in by hand — which is what they did before
 * this existed.
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

        try {
            $waybill = Delhivery::createShipment($order->loadMissing('items'));
        } catch (RuntimeException $e) {
            // Recorded rather than raised: the parcel still exists, the seller
            // can still hand it over, and an exception here would roll back a
            // fulfilment that has nothing wrong with it.
            $order->recordEvent(
                'shipment',
                'Could not book with Delhivery',
                $e->getMessage(),
                ['carrier' => 'delhivery', 'source' => 'delhivery-api'],
            );

            Log::warning('Delhivery booking failed.', [
                'order' => $order->number,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $order->forceFill([
            'carrier' => 'delhivery',
            'tracking_number' => $waybill,
        ])->save();

        $order->recordEvent(
            'shipment',
            'Booked with Delhivery',
            "Waybill {$waybill}",
            ['carrier' => 'delhivery', 'waybill' => $waybill, 'source' => 'delhivery-api'],
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
        return Delhivery::enabled()
            && filled($order->carrier)
            && mb_strtolower((string) $order->carrier) === 'delhivery'
            && blank($order->tracking_number)
            && ! in_array($order->status, ['cancelled', 'refunded'], true);
    }
}
