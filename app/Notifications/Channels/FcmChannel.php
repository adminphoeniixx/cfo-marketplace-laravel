<?php

namespace App\Notifications\Channels;

use App\Jobs\SendFcmMessage;
use App\Models\DeviceToken;
use App\Models\User;
use App\Services\Firebase\FcmClient;
use Illuminate\Notifications\Notification;

/**
 * Hands each of the user's registered devices off to a queued job, mirroring
 * the web push channel: the round trip to Google is slow and can fail, and
 * neither belongs in the request that raised the order.
 */
class FcmChannel
{
    public function __construct(private readonly FcmClient $client) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! method_exists($notification, 'toFcm')) {
            return;
        }

        if (! $this->client->enabled()) {
            return;
        }

        $message = $notification->toFcm($notifiable);

        DeviceToken::query()
            ->where('user_id', $notifiable->getKey())
            ->pluck('id')
            ->each(fn (int $id) => SendFcmMessage::dispatch($id, $message));
    }
}
