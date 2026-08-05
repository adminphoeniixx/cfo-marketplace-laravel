<?php

namespace App\Notifications;

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
        return ['database', WebPushChannel::class];
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
}
