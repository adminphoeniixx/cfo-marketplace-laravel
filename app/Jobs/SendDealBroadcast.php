<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\PushBroadcast;
use App\Notifications\Customer\DealAnnounced;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Writes one deal to every shopper who asked to hear about deals.
 *
 * Chunked rather than collected: this is the only notification in the system
 * addressed to everybody at once, and a marketplace that grows will eventually
 * run it against a hundred thousand rows. Holding those in memory to send them
 * is the version of this that works right up until the day it matters.
 *
 * The channel checks the preference again per shopper. Doing it here as well is
 * not redundant — it keeps the feed rows out too, so somebody who muted deals
 * does not open the app to find them waiting in the list.
 */
class SendDealBroadcast implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $broadcastId) {}

    public function handle(): void
    {
        $broadcast = PushBroadcast::find($this->broadcastId);

        if ($broadcast === null) {
            return;
        }

        Customer::query()
            ->where('status', 'active')
            ->where('notify_deals', true)
            ->chunkById(500, function ($customers) use ($broadcast) {
                foreach ($customers as $customer) {
                    $customer->notify(new DealAnnounced(
                        $broadcast->heading,
                        $broadcast->message,
                        $broadcast->link,
                    ));
                }
            });
    }
}
