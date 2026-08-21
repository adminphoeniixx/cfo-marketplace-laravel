<?php

namespace App\Jobs;

use App\Models\DeviceToken;
use App\Services\Firebase\FcmClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Delivers one notification to one registered device.
 *
 * A token dies the moment the app is uninstalled or Firebase rotates it, and
 * FCM answers UNREGISTERED for those. That is not worth retrying — the row is
 * simply dropped, the same way an expired web push subscription is.
 */
class SendFcmMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Give FCM room to recover before each retry rather than hammering it.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    /**
     * @param  array<string, mixed>  $message
     */
    public function __construct(
        private readonly int $deviceTokenId,
        private readonly array $message,
    ) {}

    public function handle(FcmClient $client): void
    {
        if (! $client->enabled()) {
            return;
        }

        $device = DeviceToken::find($this->deviceTokenId);

        if (! $device) {
            return;
        }

        $result = $client->send($device->token, $this->message);

        if ($result->success) {
            $device->forceFill(['last_sent_at' => now()])->save();

            return;
        }

        // The device is gone for good — stop writing to a dead token.
        if ($result->gone()) {
            $device->delete();

            return;
        }

        if ($result->retryable() && $this->attempts() < $this->tries) {
            $this->release($this->backoff()[$this->attempts() - 1] ?? 60);

            return;
        }

        // Anything else is a configuration problem (a bad project, messaging
        // not switched on) — worth a line in the log, not a stuck queue.
        Log::warning('FCM delivery failed', [
            'device_token_id' => $device->id,
            'reason' => $result->describe(),
        ]);
    }
}
