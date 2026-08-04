<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\Vendor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ShippingController extends Controller
{
    public function index(Request $request): Response
    {
        $zones = ShippingZone::query()
            ->with(['rates.vendor:id,name'])
            ->withCount('rates')
            ->when($request->string('search')->toString(), fn ($query, $search) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        return Inertia::render('admin/shipping/Index', [
            'zones' => $zones,
            'filters' => $request->only(['search']),
            'vendors' => Vendor::approved()->orderBy('name')->get(['id', 'name']),
            'rateTypes' => ShippingRate::TYPES,
            'summary' => [
                'zones' => ShippingZone::count(),
                'rates' => ShippingRate::count(),
                'free_rates' => ShippingRate::where('type', 'free')->count(),
                'collected' => (float) Order::whereNot('status', 'cancelled')->sum('shipping_total'),
            ],
        ]);
    }

    public function storeZone(Request $request): RedirectResponse
    {
        $zone = ShippingZone::create($this->validatedZone($request));

        return back()->with('success', "Zone \"{$zone->name}\" created.");
    }

    public function updateZone(Request $request, ShippingZone $shippingZone): RedirectResponse
    {
        $shippingZone->update($this->validatedZone($request));

        return back()->with('success', 'Zone updated.');
    }

    public function destroyZone(ShippingZone $shippingZone): RedirectResponse
    {
        $shippingZone->delete();

        return back()->with('success', 'Zone and its rates deleted.');
    }

    public function storeRate(Request $request): RedirectResponse
    {
        ShippingRate::create($this->validatedRate($request));

        return back()->with('success', 'Shipping rate added.');
    }

    public function updateRate(Request $request, ShippingRate $shippingRate): RedirectResponse
    {
        $shippingRate->update($this->validatedRate($request));

        return back()->with('success', 'Shipping rate updated.');
    }

    public function destroyRate(ShippingRate $shippingRate): RedirectResponse
    {
        $shippingRate->delete();

        return back()->with('success', 'Shipping rate deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validatedZone(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'countries' => ['nullable', 'array'],
            'countries.*' => ['string', 'max:2'],
            'states' => ['nullable', 'array'],
            'states.*' => ['string', 'max:80'],
            'is_active' => ['boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validatedRate(Request $request): array
    {
        return $request->validate([
            'shipping_zone_id' => ['required', 'integer', 'exists:shipping_zones,id'],
            'vendor_id' => ['nullable', 'integer', 'exists:vendors,id'],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(ShippingRate::TYPES)],
            'rate' => ['required', 'numeric', 'min:0'],
            'per_item_rate' => ['nullable', 'numeric', 'min:0'],
            'per_kg_rate' => ['nullable', 'numeric', 'min:0'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0'],
            'max_order_amount' => ['nullable', 'numeric', 'min:0', 'gt:min_order_amount'],
            'free_above_amount' => ['nullable', 'numeric', 'min:0'],
            'delivery_days_min' => ['nullable', 'integer', 'min:0', 'max:120'],
            'delivery_days_max' => ['nullable', 'integer', 'min:0', 'max:120', 'gte:delivery_days_min'],
            'is_active' => ['boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
