<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeliveryPartner;
use App\Models\Order;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Delivery partners are managed from the store settings screen, so there is no
 * index here — SettingController renders the list.
 */
class DeliveryPartnerController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $partner = DeliveryPartner::create([...$data, 'code' => Str::slug($data['name'])]);

        return back()->with('success', "Delivery partner \"{$partner->name}\" added.");
    }

    public function update(Request $request, DeliveryPartner $partner): RedirectResponse
    {
        $data = $this->validated($request, $partner);
        $previousName = $partner->name;

        $partner->update([...$data, 'code' => Str::slug($data['name'])]);

        // Fulfilled orders store the courier's name, so a rename must follow.
        if ($previousName !== $partner->name) {
            Order::where('carrier', $previousName)->update(['carrier' => $partner->name]);
        }

        return back()->with('success', 'Delivery partner updated.');
    }

    public function toggle(DeliveryPartner $partner): RedirectResponse
    {
        $partner->update(['is_active' => ! $partner->is_active]);

        return back()->with('success', $partner->is_active
            ? "\"{$partner->name}\" is now available."
            : "\"{$partner->name}\" is hidden from new fulfilments.");
    }

    public function destroy(DeliveryPartner $partner): RedirectResponse
    {
        if (Order::where('carrier', $partner->name)->exists()) {
            return back()->with('error', 'Orders were shipped with this partner — deactivate it instead.');
        }

        $partner->delete();

        return back()->with('success', 'Delivery partner deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?DeliveryPartner $partner = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:80',
                Rule::unique('delivery_partners', 'name')->ignore($partner?->id),
            ],
            // A real tracking URL carries the {tracking} placeholder, which is
            // not a legal URL character — so validate the filled-in form.
            'tracking_url' => ['nullable', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail) {
                if (! filter_var(str_replace('{tracking}', 'TRACK123', (string) $value), FILTER_VALIDATE_URL)) {
                    $fail('The tracking URL must be a valid link, optionally containing {tracking}.');
                }
            }],
            'support_phone' => ['nullable', 'string', 'max:25'],
            'notes' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'position' => ['integer', 'min:0', 'max:999'],
        ]);
    }
}
