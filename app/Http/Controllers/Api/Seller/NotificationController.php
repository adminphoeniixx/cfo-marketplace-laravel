<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\PushSubscription;
use App\Services\Firebase\FcmClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Validation\Rule;

/**
 * Notifications belong to the person, not the store, so nothing here goes
 * through `ScopesToStore` — every query hangs off the signed-in user.
 *
 * Two sellers sharing one store each get their own pile.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $notifications = $user->notifications()
            ->when($request->string('filter')->toString() === 'unread',
                fn ($query) => $query->whereNull('read_at'))
            ->when($request->string('kind')->toString(), fn ($query, $kind) => $query
                ->where('data->kind', $kind))
            ->paginate(min(max($request->integer('per_page', 25), 1), 100))
            ->withQueryString()
            ->through(fn (DatabaseNotification $n) => $this->shape($n));

        return response()->json($notifications->toArray() + [
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * Just the badge number — cheap enough to call on every app resume.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'unread' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $updated = $request->user()->notifications()
            ->whereKey($notification)
            ->update(['read_at' => now()]);

        abort_if($updated === 0, 404);

        return response()->json(['read' => true]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['unread' => 0]);
    }

    public function destroy(Request $request, string $notification): JsonResponse
    {
        $deleted = $request->user()->notifications()->whereKey($notification)->delete();

        abort_if($deleted === 0, 404);

        return response()->json(['deleted' => true]);
    }

    /**
     * Everything the app needs to decide whether to offer push at all.
     *
     * Two transports live side by side: a browser subscribes over VAPID, the
     * phone app registers a Firebase token. The app looks at whichever block
     * applies to it and ignores the other.
     */
    public function pushSettings(Request $request, FcmClient $fcm): JsonResponse
    {
        $userId = $request->user()->id;

        return response()->json([
            'enabled' => (bool) config('webpush.enabled'),
            'public_key' => config('webpush.public_key'),
            'devices' => PushSubscription::where('user_id', $userId)->count(),
            'firebase' => [
                'enabled' => $fcm->enabled(),
                'project_id' => $fcm->projectId(),
                'devices' => DeviceToken::where('user_id', $userId)->count(),
            ],
        ]);
    }

    /**
     * Remember the Firebase token for this install.
     *
     * The app should call this after every sign-in and again whenever Firebase
     * hands it a refreshed token — they rotate on their own, and a stale one
     * simply stops being delivered to.
     */
    public function registerDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:4096'],
            'platform' => ['nullable', 'string', Rule::in(DeviceToken::PLATFORMS)],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        DeviceToken::remember(
            $request->user(),
            $data['token'],
            $data['platform'] ?? 'android',
            $data['device_name'] ?? null,
        );

        return response()->json(['registered' => true], 201);
    }

    /**
     * Forget one install — called on sign-out, so the next person to use the
     * phone does not receive the last seller's orders.
     */
    public function forgetDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:4096'],
        ]);

        DeviceToken::where('user_id', $request->user()->id)
            ->where('token_hash', DeviceToken::hashFor($data['token']))
            ->delete();

        return response()->json(['registered' => false]);
    }

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

        return response()->json(['subscribed' => true], 201);
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

    /**
     * @return array<string, mixed>
     */
    private function shape(DatabaseNotification $n): array
    {
        return [
            'id' => $n->id,
            'read' => $n->read_at !== null,
            'created_at' => $n->created_at?->toIso8601String(),
            ...array_intersect_key($n->data, array_flip(['title', 'body', 'url', 'tone', 'kind'])),
        ];
    }
}
