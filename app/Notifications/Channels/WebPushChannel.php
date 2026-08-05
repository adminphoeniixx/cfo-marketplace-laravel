<?php

namespace App\Notifications\Channels;

use App\Jobs\SendWebPush;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Hands each of the user's subscribed browsers off to a queued job. The HTTP
 * call to Google/Mozilla/Apple's push service is slow and can fail, so it must
 * not sit in the request that raised the order.
 */
class WebPushChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! method_exists($notification, 'toWebPush')) {
            return;
        }

        if (! config('webpush.enabled')) {
            return;
        }

        $payload = $notification->toWebPush($notifiable);

        PushSubscription::query()
            ->where('user_id', $notifiable->getKey())
            ->pluck('id')
            ->each(fn (int $id) => SendWebPush::dispatch($id, $payload));
    }
}
