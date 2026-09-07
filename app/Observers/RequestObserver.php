<?php

namespace App\Observers;

use App\Models\Cancellation;
use App\Models\Refund;
use App\Notifications\Customer\RequestDecided;

/**
 * Tells the shopper what we decided about what they asked for.
 *
 * One observer for both models because the shopper does not experience them as
 * two systems — they asked for their money back, and either we agreed or we
 * did not.
 */
class RequestObserver
{
    public function updated(Cancellation|Refund $request): void
    {
        if (! $request->wasChanged('status')) {
            return;
        }

        $decision = (string) $request->status;

        if (! RequestDecided::worthTelling($decision)) {
            return;
        }

        $customer = $request->order?->customer;

        if ($customer === null) {
            return;
        }

        $customer->notify(new RequestDecided($request, $decision));
    }
}
