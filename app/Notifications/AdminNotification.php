<?php

namespace App\Notifications;

use App\Notifications\Channels\FcmChannel;
use App\Notifications\Channels\WebPushChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Everything the notification centre shows has the same shape: a line to read,
 * a place to go, and the admin section it belongs to. The section is what
 * decides who hears about it — see `App\Services\Notifier`.
 *
 * These are written straight to the database rather than queued, so the bell
 * updates on the very next page load. Only the push leg is deferred.
 */
abstract class AdminNotification extends Notification
{
    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', WebPushChannel::class, FcmChannel::class];
    }

    abstract public function title(): string;

    abstract public function body(): string;

    /** Where clicking the notification should land. */
    abstract public function url(): string;

    /** The section a role must hold before it is told about this. */
    abstract public static function section(): string;

    /** Badge colour, matching the tones `PBadge` already knows. */
    public function tone(): string
    {
        return 'info';
    }

    /**
     * The store this concerns, when it concerns only one. Vendor logins are
     * told about their own store and nobody else's.
     */
    public function vendorId(): ?int
    {
        return null;
    }

    /**
     * Every store this concerns.
     *
     * A basket can carry lines from two sellers, and both have to be told —
     * reading one store off the first line quietly left the second out. Most
     * notifications concern a single store and inherit this.
     *
     * @return list<int>
     */
    public function vendorIds(): array
    {
        $id = $this->vendorId();

        return $id === null ? [] : [$id];
    }

    /** Short slug used for the icon and the filter tabs. */
    public static function kind(): string
    {
        return Str::of(class_basename(static::class))->kebab()->toString();
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->title(),
            'body' => $this->body(),
            'url' => $this->url(),
            'tone' => $this->tone(),
            'kind' => static::kind(),
            'section' => static::section(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toWebPush(object $notifiable): array
    {
        return [
            'title' => $this->title(),
            'body' => $this->body(),
            'url' => $this->url(),
            'tag' => static::kind(),
        ];
    }

    /**
     * The Firebase message for the seller app, minus the device token — the
     * channel fills that in per device.
     *
     * `notification` is what the phone draws while the app is in the
     * background; `data` is what the app itself reads to route the tap. Every
     * data value has to be a string, which is why nothing here is cast.
     *
     * @return array<string, mixed>
     */
    public function toFcm(object $notifiable): array
    {
        $ttl = (int) config('firebase.ttl');

        $message = [
            'notification' => [
                'title' => $this->title(),
                'body' => $this->body(),
            ],
            'data' => [
                'url' => $this->url(),
                'kind' => static::kind(),
                'tone' => $this->tone(),
                // Older Flutter and Cordova wrappers only surface a tap when
                // this is present. Harmless everywhere else.
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
            ],
            'android' => [
                'priority' => 'high',
                'ttl' => $ttl.'s',
                'notification' => [
                    // Android 8+ drops anything whose channel it does not
                    // know, so this has to match the channel the app creates.
                    'channel_id' => (string) config('firebase.android_channel'),
                    'sound' => 'default',
                    // Same tag replaces the previous alert of that kind rather
                    // than stacking twenty "new order" bubbles.
                    'tag' => static::kind(),
                ],
            ],
            'apns' => [
                'headers' => [
                    'apns-priority' => '10',
                    'apns-expiration' => (string) (time() + $ttl),
                ],
                'payload' => [
                    'aps' => [
                        'sound' => 'default',
                        'thread-id' => static::kind(),
                    ],
                ],
            ],
        ];

        $link = $this->absoluteUrl();

        // FCM rejects a web link that is not https, so a plain http://
        // deployment simply delivers without one.
        if (str_starts_with($link, 'https://')) {
            $message['webpush'] = [
                'fcm_options' => ['link' => $link],
                'notification' => ['tag' => static::kind()],
            ];
        }

        return $message;
    }

    private function absoluteUrl(): string
    {
        $url = $this->url();

        return str_starts_with($url, 'http')
            ? $url
            : rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
    }
}
