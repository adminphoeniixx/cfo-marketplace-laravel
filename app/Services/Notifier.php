<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\AdminNotification;
use App\Support\Roles;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Works out who should hear about something, then tells them.
 *
 * Two rules decide the audience: the role has to hold the section the
 * notification belongs to, and a vendor login only ever hears about its own
 * store. Whoever performed the action is skipped — nobody needs telling about
 * what they just did themselves.
 */
class Notifier
{
    public static function send(AdminNotification $notification, ?User $actor = null): void
    {
        $recipients = self::recipients($notification, $actor);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, $notification);
    }

    /**
     * @return Collection<int, User>
     */
    public static function recipients(AdminNotification $notification, ?User $actor = null): Collection
    {
        $section = $notification::section();
        $vendorId = $notification->vendorId();

        // Roles that currently hold this section — resolved once, so the
        // lookup below is a plain `whereIn` rather than a per-user check.
        $roles = collect(Roles::permissions())
            ->filter(fn (array $sections) => in_array($section, $sections, true))
            ->keys()
            ->all();

        if ($roles === []) {
            return collect();
        }

        return User::query()
            ->where('is_active', true)
            ->whereIn('role', $roles)
            ->when($actor, fn ($query) => $query->whereKeyNot($actor->id))
            ->where(function ($query) use ($vendorId) {
                // Staff of the marketplace hear about everything they hold.
                $query->where('role', '!=', 'vendor');

                // A vendor login is only in the audience for its own store.
                if ($vendorId !== null) {
                    $query->orWhere(fn ($q) => $q
                        ->where('role', 'vendor')
                        ->where('vendor_id', $vendorId));
                }
            })
            ->get();
    }
}
