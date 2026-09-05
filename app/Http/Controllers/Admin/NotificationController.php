<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Notifications belong to the person, not to a section, so there is no
 * `role.can` gate here — everyone reaches their own and only their own.
 */
class NotificationController extends Controller
{
    /**
     * Which panel is rendering. Nothing here reads anything but the signed-in
     * user's own pile, so the seller panel reuses it and only swaps the page.
     */
    protected string $panel = 'admin';

    public function index(Request $request): Response
    {
        $user = $request->user();

        $notifications = $user->notifications()
            ->when($request->string('filter')->toString() === 'unread',
                fn ($query) => $query->whereNull('read_at'))
            ->when($request->string('kind')->toString(), fn ($query, $kind) => $query
                ->where('data->kind', $kind))
            ->paginate(25)
            ->withQueryString()
            ->through(fn (DatabaseNotification $notification) => [
                'id' => $notification->id,
                'read' => $notification->read_at !== null,
                'created_at' => $notification->created_at?->toIso8601String(),
                ...array_intersect_key($notification->data, array_flip(
                    ['title', 'body', 'url', 'tone', 'kind']
                )),
            ]);

        return Inertia::render("{$this->panel}/notifications/Index", [
            'notifications' => $notifications,
            'filters' => $request->only(['filter', 'kind']),
            'counts' => [
                'all' => $user->notifications()->count(),
                'unread' => $user->unreadNotifications()->count(),
            ],
            /*
            | The kinds this person actually has, counted.
            |
            | Read off their own pile rather than from a list of every
            | notification class: somebody who has never been sent a payout
            | alert has no use for a "payouts" tab, and a kind added next month
            | appears here without anybody remembering to register it.
            */
            'kinds' => $user->notifications()
                ->selectRaw("data->>'kind' as kind, count(*) as total")
                ->groupBy('kind')
                ->orderByDesc('total')
                ->pluck('total', 'kind')
                ->filter(fn ($total, $kind) => filled($kind))
                ->map(fn ($total, $kind) => [
                    'value' => (string) $kind,
                    // "ticket-raised" is a slug; "Ticket raised" is a tab.
                    'label' => Str::of((string) $kind)->replace('-', ' ')->ucfirst()->toString(),
                    'count' => (int) $total,
                ])
                ->values(),
            'push' => [
                'enabled' => (bool) config('webpush.enabled'),
                'publicKey' => config('webpush.public_key'),
                'devices' => PushSubscription::where('user_id', $user->id)->count(),
            ],
        ]);
    }

    public function read(Request $request, string $notification): RedirectResponse
    {
        $request->user()->notifications()
            ->whereKey($notification)
            ->update(['read_at' => now()]);

        return back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back()->with('success', 'All caught up.');
    }

    public function destroy(Request $request, string $notification): RedirectResponse
    {
        $request->user()->notifications()->whereKey($notification)->delete();

        return back();
    }

    /**
     * Clear everything already read, leaving the unread pile alone.
     */
    public function clearRead(Request $request): RedirectResponse
    {
        $deleted = $request->user()->notifications()->whereNotNull('read_at')->delete();

        return back()->with('success', $deleted === 0
            ? 'Nothing to clear.'
            : "Cleared {$deleted} read notification(s).");
    }

    /**
     * Remember a browser that has agreed to receive push notifications.
     */
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', 'string', 'max:20'],
        ]);

        PushSubscription::updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashFor($data['endpoint'])],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $data['endpoint'],
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'content_encoding' => $data['content_encoding'] ?? 'aesgcm',
            ],
        );

        return response()->json(['subscribed' => true]);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2000'],
        ]);

        PushSubscription::where('user_id', $request->user()->id)
            ->where('endpoint_hash', PushSubscription::hashFor($data['endpoint']))
            ->delete();

        return response()->json(['subscribed' => false]);
    }
}
