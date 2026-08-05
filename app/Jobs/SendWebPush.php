<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Delivers one notification to one subscribed browser.
 *
 * A subscription goes stale as soon as someone clears their site data or
 * uninstalls the browser, and the push service answers 404/410 for those. That
 * is not a failure worth retrying — the row is simply dropped.
 */
class SendWebPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly int $subscriptionId,
        private readonly array $payload,
    ) {}

    public function handle(): void
    {
        if (! config('webpush.enabled')) {
            return;
        }

        $subscription = PushSubscription::find($this->subscriptionId);

        if (! $subscription) {
            return;
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => (string) config('webpush.subject'),
                'publicKey' => (string) config('webpush.public_key'),
                'privateKey' => (string) config('webpush.private_key'),
            ],
        ]);

        $webPush->setDefaultOptions(['TTL' => (int) config('webpush.ttl')]);

        try {
            $report = $webPush->sendOneNotification(
                Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'publicKey' => $subscription->public_key,
                    'authToken' => $subscription->auth_token,
                    'contentEncoding' => $subscription->content_encoding,
                ]),
                json_encode($this->payload, JSON_THROW_ON_ERROR),
            );
        } catch (Throwable $e) {
            report($e);

            return;
        }

        if ($report->isSuccess()) {
            $subscription->forceFill(['last_sent_at' => now()])->save();

            return;
        }

        // The browser is gone for good — stop writing to a dead endpoint.
        if ($report->isSubscriptionExpired()) {
            $subscription->delete();
        }
    }
}
