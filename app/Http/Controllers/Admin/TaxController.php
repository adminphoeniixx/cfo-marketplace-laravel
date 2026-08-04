<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\TaxClass;
use App\Models\TaxRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TaxController extends Controller
{
    public function index(Request $request): Response
    {
        $classes = TaxClass::query()
            ->with(['rates' => fn ($query) => $query->orderBy('priority')->orderBy('name')])
            ->withCount(['rates', 'products'])
            ->when($request->string('search')->toString(), fn ($query, $search) => $query->where('name', 'like', "%{$search}%"))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return Inertia::render('admin/taxes/Index', [
            'classes' => $classes,
            'filters' => $request->only(['search']),
            'summary' => [
                'classes' => TaxClass::count(),
                'rates' => TaxRate::count(),
                'average_rate' => round((float) TaxRate::where('is_active', true)->avg('rate'), 2),
                'collected' => (float) Order::whereNot('status', 'cancelled')->sum('tax_total'),
            ],
        ]);
    }

    public function storeClass(Request $request): RedirectResponse
    {
        $data = $this->validatedClass($request);

        $class = TaxClass::create([...$data, 'slug' => Str::slug($data['name'])]);

        if ($class->is_default) {
            TaxClass::whereNot('id', $class->id)->update(['is_default' => false]);
        }

        return back()->with('success', "Tax class \"{$class->name}\" created.");
    }

    public function updateClass(Request $request, TaxClass $taxClass): RedirectResponse
    {
        $data = $this->validatedClass($request, $taxClass);

        $taxClass->update([...$data, 'slug' => Str::slug($data['name'])]);

        if ($taxClass->is_default) {
            TaxClass::whereNot('id', $taxClass->id)->update(['is_default' => false]);
        }

        return back()->with('success', 'Tax class updated.');
    }

    public function destroyClass(TaxClass $taxClass): RedirectResponse
    {
        if ($taxClass->products()->exists()) {
            return back()->with('error', 'Products are still assigned to this tax class.');
        }

        $taxClass->delete();

        return back()->with('success', 'Tax class deleted.');
    }

    public function storeRate(Request $request): RedirectResponse
    {
        $data = $this->validatedRate($request);

        TaxRate::create($data);

        return back()->with('success', 'Tax rate added.');
    }

    public function updateRate(Request $request, TaxRate $taxRate): RedirectResponse
    {
        $taxRate->update($this->validatedRate($request));

        return back()->with('success', 'Tax rate updated.');
    }

    public function destroyRate(TaxRate $taxRate): RedirectResponse
    {
        $taxRate->delete();

        return back()->with('success', 'Tax rate deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validatedClass(Request $request, ?TaxClass $taxClass = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('tax_classes', 'name')->ignore($taxClass)],
            'description' => ['nullable', 'string', 'max:500'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validatedRate(Request $request): array
    {
        return $request->validate([
            'tax_class_id' => ['required', 'integer', 'exists:tax_classes,id'],
            'name' => ['required', 'string', 'max:120'],
            'country' => ['required', 'string', 'max:2'],
            'state' => ['nullable', 'string', 'max:80'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:10'],
            'is_compound' => ['boolean'],
            'applies_to_shipping' => ['boolean'],
            'is_active' => ['boolean'],
        ]);
    }
}
