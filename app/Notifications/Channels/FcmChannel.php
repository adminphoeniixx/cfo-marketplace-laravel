<?php

namespace App\Notifications\Channels;

use App\Contracts\PushDevice;
use App\Jobs\SendFcmMessage;
use App\Models\Customer;
use App\Models\CustomerDeviceToken;
use App\Models\DeviceToken;
use App\Models\User;
use App\Services\Firebase\FcmClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;

/**
 * Hands each of the recipient's registered devices off to a queued job,
 * mirroring the web push channel: the round trip to Google is slow and can
 * fail, and neither belongs in the request that raised the order.
 *
 * Two audiences, two token tables. A seller and a shopper can hold the same id
 * in their own table, so the job is told which table it is reading — passing
 * only an id here would eventually deliver a payout to a shopper.
 *
 * A shopper can also switch push off, which staff cannot: the seller app is a
 * tool of the job, the shopper app is on somebody's personal phone. That is
 * why the shopper branch reads a preference and the staff branch does not.
 */
class FcmChannel
{
    public function __construct(private readonly FcmClient $client) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toFcm') || ! $this->client->enabled()) {
            return;
        }

        if ($notifiable instanceof User) {
            $this->deliver(DeviceToken::class, 'user_id', $notifiable->getKey(), $notification->toFcm($notifiable));

            return;
        }

        if ($notifiable instanceof Customer && $this->wantsPush($notifiable, $notification)) {
            $this->deliver(CustomerDeviceToken::class, 'customer_id', $notifiable->getKey(), $notification->toFcm($notifiable));
        }
    }

    /**
     * One queued job per registered device.
     *
     * @param  class-string<Model&PushDevice>  $type
     * @param  array<string, mixed>  $message
     */
    private function deliver(string $type, string $column, mixed $ownerId, array $message): void
    {
        $type::query()
            ->where($column, $ownerId)
            ->pluck('id')
            ->each(fn (int $id) => SendFcmMessage::dispatch($type, $id, $message));
    }

    /**
     * Whether this shopper has asked for this kind of push.
     *
     * `push_enabled` is the master switch; beyond it a notification may name a
     * finer preference — order updates and deals are separate rows on the
     * settings screen, and somebody who wants to know their parcel moved does
     * not thereby want to hear about a sale. A notification that names no
     * preference is transactional and goes as long as push is on at all.
     */
    private function wantsPush(Customer $customer, Notification $notification): bool
    {
        if (! $customer->push_enabled) {
            return false;
        }

        $preference = method_exists($notification, 'pushPreference')
            ? $notification->pushPreference()
            : null;

        return $preference === null || (bool) $customer->{$preference};
    }
}
