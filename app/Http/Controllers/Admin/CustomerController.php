<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $customers = Customer::query()
            ->withCount('orders')
            ->when($request->string('search')->toString(), fn ($query, $search) => $query->where(
                fn ($q) => $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
            ))
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->when($request->string('sort')->toString(), function ($query, $sort) {
                return match ($sort) {
                    'spend' => $query->orderByDesc('total_spent'),
                    'orders' => $query->orderByDesc('orders_count'),
                    'name' => $query->orderBy('first_name'),
                    default => $query->latest('id'),
                };
            }, fn ($query) => $query->latest('id'))
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('admin/customers/Index', [
            'customers' => $customers,
            'filters' => $request->only(['search', 'status', 'sort']),
            'counts' => [
                'all' => Customer::count(),
                'active' => Customer::where('status', 'active')->count(),
                'blocked' => Customer::where('status', 'blocked')->count(),
            ],
            'summary' => [
                'total_spent' => (float) Customer::sum('total_spent'),
                'average_spend' => round((float) Customer::avg('total_spent'), 2),
                'repeat_customers' => Customer::where('orders_count', '>', 1)->count(),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/customers/Form', ['customer' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $customer = Customer::create($this->validated($request));

        return to_route('admin.customers.show', $customer)
            ->with('success', "Customer \"{$customer->name}\" created.");
    }

    public function show(Customer $customer): Response
    {
        $customer->load(['addresses', 'orders' => fn ($query) => $query->latest('placed_at')->limit(20)]);

        return Inertia::render('admin/customers/Show', [
            'customer' => $customer,
            'stats' => [
                'lifetime_value' => (float) $customer->total_spent,
                'orders_count' => $customer->orders()->count(),
                'average_order' => round((float) $customer->orders()->avg('grand_total'), 2),
                'refunded' => (float) $customer->orders()->sum('refunded_total'),
                'cancelled' => $customer->orders()->where('status', 'cancelled')->count(),
            ],
        ]);
    }

    public function edit(Customer $customer): Response
    {
        return Inertia::render('admin/customers/Form', [
            'customer' => $customer->load('addresses'),
        ]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $customer->update($this->validated($request, $customer));

        return to_route('admin.customers.show', $customer)
            ->with('success', 'Customer updated.');
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $customer->delete();

        return to_route('admin.customers.index')->with('success', 'Customer deleted.');
    }

    public function toggleStatus(Customer $customer): RedirectResponse
    {
        $customer->update(['status' => $customer->status === 'active' ? 'blocked' : 'active']);

        return back()->with('success', $customer->status === 'active' ? 'Customer unblocked.' : 'Customer blocked.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?Customer $customer = null): array
    {
        return $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:180', Rule::unique('customers', 'email')->ignore($customer)],
            'phone' => ['nullable', 'string', 'max:25'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'status' => ['required', Rule::in(['active', 'blocked'])],
            'accepts_marketing' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:40'],
        ]);
    }
}
