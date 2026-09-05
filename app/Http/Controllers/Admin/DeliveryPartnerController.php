<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeliveryPartner;
use App\Models\Order;
use App\Services\Couriers\Couriers;
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

        $partner = DeliveryPartner::create([
            ...$data,
            'code' => Str::slug($data['name']),
            'credentials' => $this->credentials($data, null) ?: null,
        ]);

        return back()->with('success', "Delivery partner \"{$partner->name}\" added.");
    }

    public function update(Request $request, DeliveryPartner $partner): RedirectResponse
    {
        $data = $this->validated($request, $partner);
        $previousName = $partner->name;

        $credentials = $this->credentials($data, $partner);

        $partner->update([
            ...$data,
            'code' => Str::slug($data['name']),
            'credentials' => $credentials ?: null,
            // Changed keys are unproven keys until somebody presses Test.
            'connected_at' => $credentials === ($partner->credentials ?? []) ? $partner->connected_at : null,
            'connection_error' => null,
        ]);

        // Fulfilled orders store the courier's name, so a rename must follow.
        if ($previousName !== $partner->name) {
            Order::where('carrier', $previousName)->update(['carrier' => $partner->name]);
        }

        return back()->with('success', 'Delivery partner updated.');
    }

    /**
     * Ask the courier whether these credentials are real.
     *
     * A courier that accepts a booking and delivers nothing is a failure this
     * marketplace has already met once. Pressing this before a parcel depends
     * on it is the whole point — and it ships nothing, so it is safe to press
     * as often as you like.
     */
    public function test(DeliveryPartner $partner): RedirectResponse
    {
        $client = Couriers::for($partner);

        if ($client === null) {
            $partner->forceFill([
                'connected_at' => null,
                'connection_error' => 'No courier selected, or its credentials are incomplete.',
            ])->save();

            return back()->with('error', "\"{$partner->name}\" has nothing to connect with yet.");
        }

        $ok = $client->ping();

        $partner->forceFill([
            'connected_at' => $ok ? now() : null,
            'connection_error' => $ok ? null : 'The courier refused these credentials.',
        ])->save();

        return back()->with(
            $ok ? 'success' : 'error',
            $ok
                ? "\"{$partner->name}\" answered. Bookings will go through it."
                : "\"{$partner->name}\" refused these credentials. Nothing was shipped."
        );
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
            // Null keeps a partner what every partner used to be: a label, a
            // link and a phone number.
            'driver' => ['nullable', Rule::in(array_keys(Couriers::DRIVERS))],
            'credentials' => ['nullable', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:500'],
        ]);
    }

    /**
     * Merge what was typed over what is stored.
     *
     * A secret is written once and shown back as a row of dots, so the form
     * posts a blank for it whenever nobody retyped it. Taking that blank
     * literally would wipe a working token every time somebody corrected a
     * pickup name.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function credentials(array $data, ?DeliveryPartner $partner): array
    {
        // `$partner` is null when adding, which is the only reason for the
        // check — the column itself is nullable and handled below.
        $existing = $partner instanceof DeliveryPartner ? ($partner->credentials ?? []) : [];
        $submitted = array_filter(
            (array) ($data['credentials'] ?? []),
            fn ($value) => is_string($value) && trim($value) !== '',
        );

        return [...$existing, ...$submitted];
    }
}
