<?php

namespace App\Http\Middleware;

use App\Models\Cancellation;
use App\Models\Order;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Vendor;
use App\Support\Roles;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                // Drives which sidebar entries render. The routes are gated
                // independently, so this is presentation only.
                'sections' => $request->user()?->sections() ?? [],
                // The seller panel draws from the role's real grant, not the
                // narrowed panel view: its screens scope to the seller's own
                // store, which is the thing `sections` is narrowed for.
                'sellerSections' => $request->user()?->isVendor()
                    ? Roles::forRole($request->user()->role)
                    : [],
            ],
            // The seller's own store, for the panel header. Null for staff.
            'store' => $request->user()?->isVendor()
                ? $request->user()->vendor?->only(['id', 'name', 'status'])
                : null,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'adminCounts' => fn () => $request->user() && $request->is('admin*')
                ? $this->adminCounts()
                : null,
            'sellerCounts' => fn () => $request->user()?->isVendor() && $request->is('seller*')
                ? $this->sellerCounts($request)
                : null,
            // Named for the topbar bell rather than "notifications", which the
            // notification centre already uses for its own paginator — a page
            // prop of the same name would shadow this one and blank the badge.
            'bell' => fn () => $request->user() && $request->is('admin*', 'seller*')
                ? $this->bell($request)
                : null,
        ];
    }

    /**
     * What the topbar bell needs: the unread count and enough of the newest
     * few to fill the dropdown without a second request.
     *
     * @return array{unread: int, recent: array<int, array<string, mixed>>}
     */
    protected function bell(Request $request): array
    {
        $user = $request->user();

        return [
            'unread' => $user->unreadNotifications()->count(),
            'recent' => $user->notifications()
                ->latest()
                ->limit(6)
                ->get()
                ->map(fn (DatabaseNotification $notification) => [
                    'id' => $notification->id,
                    'read' => $notification->read_at !== null,
                    'created_at' => $notification->created_at?->toIso8601String(),
                    ...array_intersect_key($notification->data, array_flip(
                        ['title', 'body', 'url', 'tone', 'kind']
                    )),
                ])
                ->all(),
        ];
    }

    /**
     * Badge counts for the seller sidebar — this store's numbers only.
     *
     * Deliberately not `adminCounts()`: those are marketplace-wide totals, and
     * showing a seller the whole marketplace's open-order count would both
     * mislead them and leak the platform's volume.
     *
     * @return array<string, int>
     */
    protected function sellerCounts(Request $request): array
    {
        $storeId = (int) $request->user()->vendor_id;

        return [
            'products' => Product::where('vendor_id', $storeId)->count(),
            'low_stock' => Product::where('vendor_id', $storeId)
                ->where('track_inventory', true)
                ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
                ->count(),
            // Counted from this store's own lines, not the order's
            // `fulfillment_status`: on a basket shared with another seller that
            // stays partial until *they* ship, which would nag this seller
            // about an order they have already finished.
            'to_pack' => Order::query()
                ->whereNot('status', 'cancelled')
                ->whereHas('items', fn ($query) => $query
                    ->where('vendor_id', $storeId)
                    ->whereRaw('order_items.quantity_fulfilled < order_items.quantity - order_items.quantity_cancelled'))
                ->count(),
            'unread' => $request->user()->unreadNotifications()->count(),
        ];
    }

    /**
     * Badge counts shown in the admin sidebar.
     *
     * @return array<string, int>
     */
    protected function adminCounts(): array
    {
        return [
            'open_orders' => Order::whereIn('status', ['pending', 'processing'])->count(),
            'pending_cancellations' => Cancellation::where('status', 'pending')->count(),
            'pending_refunds' => Refund::where('status', 'pending')->count(),
            'pending_vendors' => Vendor::where('status', 'pending')->count(),
        ];
    }
}
