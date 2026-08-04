<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CustomerAddressController extends Controller
{
    public function store(Request $request, Customer $customer): RedirectResponse
    {
        $data = $this->validated($request);

        $address = $customer->addresses()->create($data);
        $this->enforceDefaults($customer, $address);

        return back()->with('success', 'Address added.');
    }

    public function update(Request $request, Customer $customer, CustomerAddress $address): RedirectResponse
    {
        abort_unless($address->customer_id === $customer->id, 404);

        $address->update($this->validated($request));
        $this->enforceDefaults($customer, $address);

        return back()->with('success', 'Address updated.');
    }

    public function destroy(Customer $customer, CustomerAddress $address): RedirectResponse
    {
        abort_unless($address->customer_id === $customer->id, 404);

        $address->delete();

        return back()->with('success', 'Address removed.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:40'],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'company' => ['nullable', 'string', 'max:120'],
            'address_line1' => ['required', 'string', 'max:200'],
            'address_line2' => ['nullable', 'string', 'max:200'],
            'city' => ['required', 'string', 'max:80'],
            'state' => ['nullable', 'string', 'max:80'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'country' => ['required', 'string', 'max:2'],
            'phone' => ['nullable', 'string', 'max:25'],
            'is_default_billing' => ['boolean'],
            'is_default_shipping' => ['boolean'],
        ]);
    }

    /**
     * Only one address may be the default for billing or shipping.
     */
    protected function enforceDefaults(Customer $customer, CustomerAddress $address): void
    {
        if ($address->is_default_billing) {
            $customer->addresses()->whereNot('id', $address->id)->update(['is_default_billing' => false]);
        }

        if ($address->is_default_shipping) {
            $customer->addresses()->whereNot('id', $address->id)->update(['is_default_shipping' => false]);
        }
    }
}
