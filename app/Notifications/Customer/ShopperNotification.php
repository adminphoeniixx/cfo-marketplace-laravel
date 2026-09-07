<?php

namespace App\Notifications\Customer;

use App\Notifications\Channels\FcmChannel;
use Illuminate\Notifications\Notification;

/**
 * Everything the shopper app shows, in one shape.
 *
 * The sibling of `AdminNotification`, and deliberately not a subclass of it:
 * that one carries a `section` deciding which staff role hears about it, which
 * is meaningless here — a shopper's notification has exactly one recipient,
 * and it is the shopper. What this adds instead is a preference, because a
 * shopper may switch push off and staff may not.
 *
 * Written to the database first and pushed second. The feed is the record; the
 * push is a tap on the shoulder about it, and a phone that is off, uninstalled
 * or out of battery must not cost the shopper the notification itself.
 */
abstract class ShopperNotification extends Notification
{
    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', FcmChannel::class];
    }

    abstract public function title(): string;

    abstract public function body(): string;

    /**
     * Where tapping it should take the app — a route inside the app, not a URL.
     * The shopper app owns its own navigation; handing it a web address would
     * bounce somebody out of the app to read about their own order.
     */
    public function link(): ?string
    {
        return null;
    }

    /** Short slug the app uses to pick an icon and to group alerts. */
    abstract public static function kind(): string;

    /**
     * The preference column that governs this, or null for something
     * transactional that goes whenever push is on at all.
     *
     * A shopper who turned off "deals" has not asked to stop hearing that
     * their parcel was delivered, and the two must not share a switch.
     */
    public function pushPreference(): ?string
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return array_filter([
            'title' => $this->title(),
            'body' => $this->body(),
            'kind' => static::kind(),
            'link' => $this->link(),
        ], fn ($value) => $value !== null);
    }

    /**
     * The Firebase message, minus the device token — the channel fills that in
     * per device.
     *
     * `notification` is what the phone draws while the app is in the
     * background; `data` is what the app reads to route the tap. Every data
     * value has to be a string, which is why nothing here is cast.
     *
     * @return array<string, mixed>
     */
    public function toFcm(object $notifiable): array
    {
        $ttl = (int) config('firebase.ttl');

        return [
            'notification' => [
                'title' => $this->title(),
                'body' => $this->body(),
            ],
            'data' => array_filter([
                'kind' => static::kind(),
                'link' => $this->link(),
                // Older Flutter and Cordova wrappers only surface a tap when
                // this is present. Harmless everywhere else.
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
            ], fn ($value) => $value !== null),
            'android' => [
                'priority' => 'high',
                'ttl' => $ttl.'s',
                'notification' => [
                    // Android 8+ drops anything whose channel it does not
                    // know, so this has to match the channel the app creates.
                    'channel_id' => (string) config('firebase.android_channel'),
                    'sound' => 'default',
                    // Grouped per order rather than per kind: two updates about
                    // the same parcel replace each other, but an update about a
                    // different parcel must not silently swallow the first.
                    'tag' => $this->groupTag(),
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
                        'thread-id' => $this->groupTag(),
                    ],
                ],
            ],
        ];
    }

    /**
     * What a new alert of this sort replaces on the lock screen. Defaults to
     * the kind; anything about a particular thing should narrow it.
     */
    protected function groupTag(): string
    {
        return static::kind();
    }
}
