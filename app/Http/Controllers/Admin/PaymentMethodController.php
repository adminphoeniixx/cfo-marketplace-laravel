<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentMethod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PaymentMethodController extends Controller
{
    public function index(): Response
    {
        // Orders keep the method's label, so usage is counted by name.
        $usage = Order::query()
            ->whereNotNull('payment_method')
            ->selectRaw('payment_method, count(*) as total, coalesce(sum(grand_total), 0) as revenue')
            ->whereNot('status', 'cancelled')
            ->groupBy('payment_method')
            ->get()
            ->keyBy('payment_method');

        $methods = PaymentMethod::orderBy('position')->orderBy('name')->get()
            ->map(fn (PaymentMethod $method) => [
                ...$method->only(['id', 'name', 'code', 'description', 'icon', 'is_active', 'position']),
                // What the shopper app actually draws: the admin's own glyph,
                // or the one derived from the code where they set none.
                'glyph' => $method->glyph(),
                'orders_count' => (int) ($usage[$method->name]->total ?? 0),
                'revenue' => (float) ($usage[$method->name]->revenue ?? 0),
            ]);

        return Inertia::render('admin/payments/Index', [
            'methods' => $methods,
            // Values sitting on orders that no longer match a configured method.
            'unlisted' => $usage->keys()
                ->diff($methods->pluck('name'))
                ->values(),
            'summary' => [
                'total' => $methods->count(),
                'active' => $methods->where('is_active', true)->count(),
                'orders' => (int) $usage->sum('total'),
                'revenue' => (float) $usage->sum('revenue'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $method = PaymentMethod::create([...$data, 'code' => Str::slug($data['name'])]);

        return back()->with('success', "Payment method \"{$method->name}\" added.");
    }

    public function update(Request $request, PaymentMethod $payment): RedirectResponse
    {
        $data = $this->validated($request, $payment);
        $previousName = $payment->name;

        $payment->update([...$data, 'code' => Str::slug($data['name'])]);

        // Existing orders store the label, so a rename would orphan them.
        if ($previousName !== $payment->name) {
            Order::where('payment_method', $previousName)->update(['payment_method' => $payment->name]);
        }

        return back()->with('success', 'Payment method updated.');
    }

    public function toggle(PaymentMethod $payment): RedirectResponse
    {
        $payment->update(['is_active' => ! $payment->is_active]);

        return back()->with('success', $payment->is_active
            ? "\"{$payment->name}\" is now available."
            : "\"{$payment->name}\" is hidden from new orders.");
    }

    public function destroy(PaymentMethod $payment): RedirectResponse
    {
        if (Order::where('payment_method', $payment->name)->exists()) {
            return back()->with('error', 'Orders still use this method — deactivate it instead.');
        }

        $payment->delete();

        return back()->with('success', 'Payment method deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?PaymentMethod $payment = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('payment_methods', 'name')->ignore($payment?->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            // Drawn beside the method in the shopper app. Blank is fine — one
            // is derived from the code instead.
            'icon' => ['nullable', 'string', 'max:8'],
            'is_active' => ['boolean'],
            'position' => ['integer', 'min:0', 'max:999'],
        ]);
    }
}
