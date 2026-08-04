<?php

namespace App\Http\Middleware;

use App\Models\Cancellation;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Vendor;
use Illuminate\Http\Request;
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
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'adminCounts' => fn () => $request->user() && $request->is('admin*')
                ? $this->adminCounts()
                : null,
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
