<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\CustomerDeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Validation\Rule;

/**
 * The shopper's own notification feed, and the push tokens behind it.
 *
 * Same shape as the seller app's: a paginated list that carries the badge
 * number with it, so the app does not need a second call to draw the bell.
 */
class NotificationController extends Controller
{
    use ScopesToCustomer;

    public function index(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        $notifications = $customer->notifications()
            ->when($request->string('filter')->toString() === 'unread',
                fn ($query) => $query->whereNull('read_at'))
            ->paginate($this->perPage($request))
            ->withQueryString()
            ->through(fn (DatabaseNotification $n) => [
                'id' => $n->id,
                'title' => $n->data['title'] ?? '',
                'body' => $n->data['body'] ?? '',
                'kind' => $n->data['kind'] ?? null,
                // Where tapping it should take the app, when the sender said.
                'link' => $n->data['link'] ?? null,
                'read' => $n->read_at !== null,
                'created_at' => $n->created_at?->toIso8601String(),
            ]);

        return response()->json($notifications->toArray() + [
            'unread_count' => $customer->unreadNotifications()->count(),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'unread' => $this->customer($request)->unreadNotifications()->count(),
        ]);
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $updated = $this->customer($request)->notifications()
            ->whereKey($notification)
            ->update(['read_at' => now()]);

        abort_if($updated === 0, 404);

        return response()->json(['read' => true]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $this->customer($request)->unreadNotifications->markAsRead();

        return response()->json(['read' => true]);
    }

    public function destroy(Request $request, string $notification): JsonResponse
    {
        $deleted = $this->customer($request)->notifications()->whereKey($notification)->delete();

        abort_if($deleted === 0, 404);

        return response()->json(['deleted' => true]);
    }

    /**
     * Register this install for push.
     */
    public function registerDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:4096'],
            'platform' => ['nullable', Rule::in(CustomerDeviceToken::PLATFORMS)],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        CustomerDeviceToken::remember(
            $this->customer($request),
            $data['token'],
            $data['platform'] ?? 'android',
            $data['device_name'] ?? null,
        );

        return response()->json(['registered' => true]);
    }

    public function forgetDevice(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:4096']]);

        CustomerDeviceToken::where('token_hash', CustomerDeviceToken::hashFor($data['token']))->delete();

        return response()->json(['registered' => false]);
    }
}
