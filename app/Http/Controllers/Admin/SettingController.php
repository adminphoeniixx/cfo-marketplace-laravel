<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeliveryPartner;
use App\Models\Order;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingController extends Controller
{
    /**
     * @var array<string, string>
     */
    protected array $defaults = [
        'store_name' => 'Marketplace',
        'store_email' => 'support@marketplace.test',
        'store_phone' => '',
        // Where "Need help with this order" goes in the shopper app. Whatever
        // the marketplace actually answers on — WhatsApp, a helpdesk, a page.
        'support_chat_url' => '',
        // Named on the help screen beside the chat button, and only ever
        // shown where there is a URL to go with it.
        'support_chat_provider' => '',
        'support_hours' => '',
        // "Sell on <marketplace>" in the shopper app's account screen.
        'seller_onboarding_url' => '',
        'currency' => 'INR',
        'weight_unit' => 'kg',
        'order_prefix' => '#',
        'default_commission' => '10',
        'auto_approve_vendors' => '0',
        'auto_approve_cancellations' => '0',
        'low_stock_threshold' => '5',
        'address' => '',
    ];

    public function index(): Response
    {
        $stored = Setting::pluck('value', 'key')->all();

        // Orders keep the courier's name, so usage is counted by name.
        $shipments = Order::query()
            ->whereNotNull('carrier')
            ->selectRaw('carrier, count(*) as total')
            ->groupBy('carrier')
            ->pluck('total', 'carrier');

        $partners = DeliveryPartner::orderBy('position')->orderBy('name')->get()
            ->map(fn (DeliveryPartner $partner) => [
                ...$partner->only(['id', 'name', 'code', 'tracking_url', 'support_phone', 'notes', 'is_active', 'position']),
                'orders_count' => (int) ($shipments[$partner->name] ?? 0),
            ]);

        return Inertia::render('admin/Settings', [
            'settings' => [...$this->defaults, ...$stored],
            'deliveryPartners' => $partners,
            // Couriers sitting on orders that are not in the managed list.
            'unlistedPartners' => $shipments->keys()->diff($partners->pluck('name'))->values(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'store_name' => ['required', 'string', 'max:120'],
            'store_email' => ['required', 'email', 'max:180'],
            'store_phone' => ['nullable', 'string', 'max:25'],
            'support_chat_url' => ['nullable', 'url', 'max:255'],
            'support_chat_provider' => ['nullable', 'string', 'max:40'],
            'support_hours' => ['nullable', 'string', 'max:120'],
            'seller_onboarding_url' => ['nullable', 'url', 'max:255'],
            'currency' => ['required', 'string', 'max:3'],
            'weight_unit' => ['required', 'string', 'max:5'],
            'order_prefix' => ['required', 'string', 'max:5'],
            'default_commission' => ['required', 'numeric', 'min:0', 'max:100'],
            'auto_approve_vendors' => ['boolean'],
            'auto_approve_cancellations' => ['boolean'],
            'low_stock_threshold' => ['required', 'integer', 'min:0'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        foreach ($data as $key => $value) {
            Setting::put($key, is_bool($value) ? (string) (int) $value : (string) $value);
        }

        return back()->with('success', 'Store settings saved.');
    }
}
