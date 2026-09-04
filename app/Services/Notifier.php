<?php

namespace App\Services;

use App\Models\User;
use App\Models\Vendor;
use App\Notifications\AdminNotification;
use App\Support\Roles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

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

        self::deliver($recipients, $notification);
    }

    /**
     * Send, and survive a mail server having a bad morning.
     *
     * Nothing here is queued, so an unreachable — or misconfigured — SMTP
     * relay throws straight out of whatever request triggered it: a seller
     * signing up, a shopper asking for a password link, an order being placed.
     * A marketplace that cannot register a seller because a mail relay is down
     * is a worse failure than a notification nobody received, so the send is
     * logged and the request carries on.
     *
     * The database copy is already written by then, so the bell in the panel
     * still shows it.
     *
     * @param  Collection<int, User>|User  $recipients
     */
    protected static function deliver(Collection|User $recipients, AdminNotification $notification): void
    {
        try {
            Notification::send($recipients, $notification);
        } catch (TransportExceptionInterface $e) {
            Log::error('A notification could not be emailed.', [
                'notification' => $notification::class,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Tell one store's own logins, whatever the role matrix says.
     *
     * A handful of messages are addressed to a store rather than to a section:
     * "your store was approved" is the obvious one — the seller has to hear it,
     * and no section in the matrix means "news about yourself". Everything else
     * should go through `send()`.
     */
    public static function toStore(Vendor $vendor, AdminNotification $notification, ?User $actor = null): void
    {
        $recipients = $vendor->users()
            ->where('is_active', true)
            ->when($actor, fn ($query) => $query->whereKeyNot($actor->id))
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        self::deliver($recipients, $notification);
    }

    /**
     * Tell everyone something concerns, each in their own terms.
     *
     * Marketplace staff hear about the whole thing once. Every store involved
     * gets its own copy, built for that store — which is what lets a message
     * quote a seller's own share rather than the buyer's basket total. Use
     * this wherever the body carries money; `send()` is fine for the rest.
     *
     * @param  list<int>  $vendorIds
     * @param  callable(?int): AdminNotification  $make  null builds the staff copy
     */
    public static function sendPerStore(array $vendorIds, callable $make, ?User $actor = null): void
    {
        $staffCopy = $make(null);
        $staff = self::staffFor($staffCopy::section(), $actor);

        if ($staff->isNotEmpty()) {
            self::deliver($staff, $staffCopy);
        }

        foreach (array_unique($vendorIds) as $vendorId) {
            $copy = $make($vendorId);
            $sellers = self::storesFor($copy::section(), [$vendorId], $actor);

            if ($sellers->isNotEmpty()) {
                self::deliver($sellers, $copy);
            }
        }
    }

    /**
     * @return Collection<int, User>
     */
    public static function recipients(AdminNotification $notification, ?User $actor = null): Collection
    {
        $section = $notification::section();

        return self::staffFor($section, $actor)
            ->merge(self::storesFor($section, $notification->vendorIds(), $actor));
    }

    /**
     * Marketplace staff whose role holds the section.
     *
     * @return Collection<int, User>
     */
    private static function staffFor(string $section, ?User $actor): Collection
    {
        return self::holding($section, $actor)
            ?->where('role', '!=', 'vendor')->get() ?? collect();
    }

    /**
     * Logins of the given stores, when the vendor role holds the section.
     *
     * @param  list<int>  $vendorIds
     * @return Collection<int, User>
     */
    private static function storesFor(string $section, array $vendorIds, ?User $actor): Collection
    {
        if ($vendorIds === []) {
            return collect();
        }

        return self::holding($section, $actor)
            ?->where('role', 'vendor')->whereIn('vendor_id', $vendorIds)->get() ?? collect();
    }

    /**
     * Active users whose role currently holds the section, minus the actor.
     *
     * Null when no role holds it at all, which saves the callers a query.
     *
     * @return Builder<User>|null
     */
    private static function holding(string $section, ?User $actor): ?Builder
    {
        // Roles that currently hold this section — resolved once, so the
        // lookup is a plain `whereIn` rather than a per-user check.
        $roles = collect(Roles::permissions())
            ->filter(fn (array $sections) => in_array($section, $sections, true))
            ->keys()
            ->all();

        if ($roles === []) {
            return null;
        }

        return User::query()
            ->where('is_active', true)
            ->whereIn('role', $roles)
            ->when($actor, fn ($query) => $query->whereKeyNot($actor->id));
    }
}
