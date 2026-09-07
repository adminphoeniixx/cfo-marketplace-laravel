<?php

namespace App\Observers;

use App\Models\Order;
use App\Notifications\Customer\OrderProgressed;

/**
 * Tells the shopper their order moved, from wherever it moved.
 *
 * An order's status is written in thirty-odd places — a seller packing it, an
 * admin overriding it, a courier webhook, the nightly sync, a cancellation
 * being approved. Hooking each one would have meant finding each one, and
 * missing the next one somebody adds. The model is the one thing they all go
 * through, so the notification hangs off the model.
 *
 * Nothing here throws. A shopper not hearing about a delivery is bad; a
 * courier's webhook 500ing because of it is worse, and would leave the parcel's
 * own state unrecorded too.
 */
class OrderObserver
{
    public function updated(Order $order): void
    {
        $customer = $order->customer;

        if ($customer === null) {
            // A manual order raised in the panel for a walk-in has nobody to
            // notify. Not an error — there is simply no app on the other end.
            return;
        }

        // The courier's word beats ours when both moved in the same save: it
        // is the one that watched the box, and two alerts for one event is how
        // a shopper learns to swipe them away unread.
        $field = match (true) {
            $order->wasChanged('shipment_status') => 'shipment_status',
            $order->wasChanged('status') => 'status',
            default => null,
        };

        if ($field === null) {
            return;
        }

        $to = (string) $order->{$field};

        if ($to === '' || ! OrderProgressed::worthTelling($field, $to)) {
            return;
        }

        $customer->notify(new OrderProgressed($order, $to));
    }
}
