<?php

namespace App\Jobs;

use App\Contracts\PushDevice;
use App\Services\Firebase\FcmClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
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
     * @param  class-string<Model&PushDevice>  $deviceType  Which table the id
     *                                                      belongs to. A seller
     *                                                      and a shopper both
     *                                                      have a device 7.
     * @param  array<string, mixed>  $message
     */
    public function __construct(
        private readonly string $deviceType,
        private readonly int $deviceTokenId,
        private readonly array $message,
    ) {}

    public function handle(FcmClient $client): void
    {
        if (! $client->enabled()) {
            return;
        }

        $device = $this->deviceType::find($this->deviceTokenId);

        if (! $device instanceof PushDevice) {
            return;
        }

        $result = $client->send($device->pushToken(), $this->message);

        if ($result->success) {
            $device->markPushSent();

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
            'device' => $this->deviceType.'#'.$device->getKey(),
            'reason' => $result->describe(),
        ]);
    }
}
