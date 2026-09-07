<?php

namespace App\Actions\Shipping;

use App\Models\Order;
use App\Services\Couriers\Couriers;

/**
 * The label for a parcel, fetched once and remembered.
 *
 * Without this a seller books a parcel here and then signs into the courier's
 * own panel to print the thing that goes on the box — which is most of the
 * reason a marketplace integrates a courier at all.
 *
 * Both providers charge a call for a label and both host the PDF themselves,
 * so the URL is stored on the order: printing twice costs one call, and a
 * packing table that reprints a smudged label all morning costs the same.
 */
class FetchLabel
{
    /**
     * The label URL, generating it if this is the first time anybody asked.
     *
     * @param  bool  $refresh  Ask again even if one is stored — for a link the
     *                         courier has since expired.
     */
    public function handle(Order $order, bool $refresh = false): ?string
    {
        if (blank($order->tracking_number)) {
            return null;
        }

        if (! $refresh && filled($order->shipment_label_url)) {
            return $order->shipment_label_url;
        }

        $courier = Couriers::forCarrier($order->carrier);

        if ($courier === null) {
            return null;
        }

        $url = $courier->label((string) $order->tracking_number);

        if ($url === null) {
            // Normal rather than exceptional right after booking: a parcel
            // that is not manifested yet has no label to print, and the
            // seller's next attempt in a minute will get one.
            return null;
        }

        $order->forceFill(['shipment_label_url' => $url])->save();

        return $url;
    }
}
