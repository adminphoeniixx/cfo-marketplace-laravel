<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use App\Models\VendorPayout;
use App\Notifications\PayoutRecorded;
use App\Services\Notifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PayoutController extends Controller
{
    public function index(Request $request): Response
    {
        $payouts = VendorPayout::query()
            ->with('vendor:id,name,payout_method')
            ->when($request->string('search')->toString(), fn ($query, $search) => $query->where(
                fn ($q) => $q->where('number', 'like', "%{$search}%")
                    ->orWhereHas('vendor', fn ($v) => $v->where('name', 'like', "%{$search}%"))
            ))
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->when($request->filled('vendor'), fn ($query) => $query->where('vendor_id', $request->integer('vendor')))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('admin/payouts/Index', [
            'payouts' => $payouts,
            'filters' => $request->only(['search', 'status', 'vendor']),
            'vendors' => Vendor::approved()->orderBy('name')->get(['id', 'name']),
            'statuses' => VendorPayout::STATUSES,
            'counts' => collect(VendorPayout::STATUSES)
                ->mapWithKeys(fn ($status) => [$status => VendorPayout::where('status', $status)->count()])
                ->put('all', VendorPayout::count()),
            'summary' => [
                'pending' => (float) VendorPayout::whereIn('status', ['pending', 'processing'])->sum('net_amount'),
                'paid' => (float) VendorPayout::where('status', 'paid')->sum('net_amount'),
                'commission_earned' => (float) VendorPayout::sum('commission_amount'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'adjustment_amount' => ['nullable', 'numeric'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $vendor = Vendor::findOrFail((int) $data['vendor_id']);
        $start = Carbon::parse($data['period_start'])->startOfDay();
        $end = Carbon::parse($data['period_end'])->endOfDay();

        $items = $vendor->orderItems()
            ->whereHas('order', fn ($query) => $query
                ->whereBetween('placed_at', [$start, $end])
                ->whereNotIn('status', ['cancelled']))
            ->get();

        $gross = (float) $items->sum('total');
        $commission = (float) $items->sum('commission_amount');
        $adjustment = (float) ($data['adjustment_amount'] ?? 0);

        $payout = VendorPayout::create([
            'number' => VendorPayout::nextNumber(),
            'vendor_id' => $vendor->id,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'gross_sales' => $gross,
            'commission_amount' => $commission,
            'adjustment_amount' => $adjustment,
            'net_amount' => round($gross - $commission + $adjustment, 2),
            'orders_count' => $items->unique('order_id')->count(),
            'status' => 'pending',
            'method' => $vendor->payout_method,
            'note' => $data['note'] ?? null,
        ]);

        Notifier::send(new PayoutRecorded($payout), $request->user());

        return to_route('admin.payouts.index')
            ->with('success', "Payout {$payout->number} generated for {$vendor->name}.");
    }

    public function updateStatus(Request $request, VendorPayout $payout): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(VendorPayout::STATUSES)],
            'transaction_reference' => ['nullable', 'string', 'max:120'],
        ]);

        $payout->update([
            'status' => $data['status'],
            'transaction_reference' => $data['transaction_reference'] ?? $payout->transaction_reference,
            'paid_at' => $data['status'] === 'paid' ? now() : null,
        ]);

        return back()->with('success', "Payout marked as {$data['status']}.");
    }

    public function destroy(VendorPayout $payout): RedirectResponse
    {
        abort_if($payout->status === 'paid', 422, 'A paid payout cannot be deleted.');

        $payout->delete();

        return back()->with('success', 'Payout deleted.');
    }
}
