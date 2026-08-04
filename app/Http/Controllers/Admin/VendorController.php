<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Vendor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class VendorController extends Controller
{
    public function index(Request $request): Response
    {
        $vendors = Vendor::query()
            ->withCount(['products', 'payouts'])
            ->withSum('orderItems as revenue', 'total')
            ->withSum('orderItems as commission', 'commission_amount')
            ->when($request->string('search')->toString(), fn ($query, $search) => $query->where(
                fn ($q) => $q->where('name', 'like', "%{$search}%")
                    ->orWhere('store_email', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
            ))
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('admin/vendors/Index', [
            'vendors' => $vendors,
            'filters' => $request->only(['search', 'status']),
            'statuses' => Vendor::STATUSES,
            'counts' => collect(Vendor::STATUSES)
                ->mapWithKeys(fn ($status) => [$status => Vendor::where('status', $status)->count()])
                ->put('all', Vendor::count()),
            'summary' => [
                'gmv' => (float) OrderItem::whereNotNull('vendor_id')->sum('total'),
                'commission' => (float) OrderItem::whereNotNull('vendor_id')->sum('commission_amount'),
                'payable' => (float) OrderItem::whereNotNull('vendor_id')->sum('vendor_earning'),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/vendors/Form', ['vendor' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $vendor = Vendor::create($this->validated($request));

        return to_route('admin.vendors.show', $vendor)
            ->with('success', "Vendor \"{$vendor->name}\" created.");
    }

    public function show(Request $request, Vendor $vendor): Response
    {
        $revenue = (float) $vendor->orderItems()->sum('total');
        $commission = (float) $vendor->orderItems()->sum('commission_amount');

        return Inertia::render('admin/vendors/Show', [
            'vendor' => $vendor,
            'stats' => [
                'revenue' => $revenue,
                'commission' => $commission,
                'earning' => (float) $vendor->orderItems()->sum('vendor_earning'),
                'units_sold' => (int) $vendor->orderItems()->sum('quantity'),
                'orders' => $vendor->orderItems()->distinct('order_id')->count('order_id'),
                'products' => $vendor->products()->count(),
                'active_products' => $vendor->products()->where('status', 'active')->count(),
                'paid_out' => (float) $vendor->payouts()->where('status', 'paid')->sum('net_amount'),
            ],
            'products' => $vendor->products()
                ->with('category:id,name')
                ->latest('id')
                ->limit(10)
                ->get(['id', 'name', 'sku', 'price', 'stock_quantity', 'status', 'category_id']),
            'recentOrders' => Order::query()
                ->whereHas('items', fn ($query) => $query->where('vendor_id', $vendor->id))
                ->with('customer:id,first_name,last_name')
                ->latest('placed_at')
                ->limit(10)
                ->get(['id', 'number', 'customer_id', 'status', 'grand_total', 'placed_at']),
            'payouts' => $vendor->payouts()->latest('id')->limit(10)->get(),
        ]);
    }

    public function edit(Vendor $vendor): Response
    {
        return Inertia::render('admin/vendors/Form', ['vendor' => $vendor]);
    }

    public function update(Request $request, Vendor $vendor): RedirectResponse
    {
        $vendor->update($this->validated($request, $vendor));

        return to_route('admin.vendors.show', $vendor)->with('success', 'Vendor updated.');
    }

    public function destroy(Vendor $vendor): RedirectResponse
    {
        if ($vendor->products()->exists()) {
            return back()->with('error', 'Reassign or delete this vendor\'s products first.');
        }

        $vendor->delete();

        return to_route('admin.vendors.index')->with('success', 'Vendor deleted.');
    }

    public function updateStatus(Request $request, Vendor $vendor): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(Vendor::STATUSES)],
            'rejection_reason' => ['required_if:status,rejected', 'nullable', 'string', 'max:500'],
        ]);

        $vendor->update([
            'status' => $data['status'],
            'rejection_reason' => $data['status'] === 'rejected' ? $data['rejection_reason'] : null,
            'approved_at' => $data['status'] === 'approved' ? ($vendor->approved_at ?? now()) : $vendor->approved_at,
        ]);

        if ($data['status'] === 'suspended') {
            $vendor->products()->update(['status' => 'draft']);
        }

        return back()->with('success', "Vendor {$data['status']}.");
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?Vendor $vendor = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:170', Rule::unique('vendors', 'slug')->ignore($vendor)],
            'store_email' => ['nullable', 'email', 'max:180'],
            'phone' => ['nullable', 'string', 'max:25'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(Vendor::STATUSES)],
            'commission_type' => ['required', Rule::in(['percentage', 'flat'])],
            'commission_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'address_line1' => ['nullable', 'string', 'max:200'],
            'address_line2' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:80'],
            'state' => ['nullable', 'string', 'max:80'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'country' => ['required', 'string', 'max:2'],
            'gst_number' => ['nullable', 'string', 'max:20'],
            'payout_method' => ['required', Rule::in(['bank', 'upi', 'paypal', 'manual'])],
            'bank_account_name' => ['nullable', 'string', 'max:120'],
            'bank_account_number' => ['nullable', 'string', 'max:40'],
            'bank_ifsc' => ['nullable', 'string', 'max:20'],
        ]);
    }
}
