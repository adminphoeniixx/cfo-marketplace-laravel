<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\AddressResource;
use App\Models\CustomerAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AddressController extends Controller
{
    use ScopesToCustomer;

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => AddressResource::collection(
                $this->customer($request)->addresses()->orderByDesc('is_default_shipping')->get()
            ),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $data = $request->validate($this->rules());

        $address = $customer->addresses()->create($data);

        // The first address a shopper saves is the one we deliver to.
        if ($data['is_default_shipping'] ?? $customer->addresses()->count() === 1) {
            $this->makeDefault($request, $address->id);
            $address->refresh();
        }

        return response()->json(['data' => new AddressResource($address)], 201);
    }

    public function update(Request $request, int $address): JsonResponse
    {
        $model = $this->findOwned($request, $address);
        $model->update($request->validate($this->rules()));

        if ($request->boolean('is_default_shipping')) {
            $this->makeDefault($request, $model->id);
        }

        return response()->json(['data' => new AddressResource($model->fresh())]);
    }

    public function destroy(Request $request, int $address): JsonResponse
    {
        $customer = $this->customer($request);

        if ($customer->addresses()->count() === 1) {
            throw ValidationException::withMessages([
                'address' => 'Keep at least one address — orders have to go somewhere.',
            ]);
        }

        $model = $this->findOwned($request, $address);
        $wasDefault = $model->is_default_shipping;
        $model->delete();

        // Never leave the account without a default: promote whatever is left.
        if ($wasDefault && $next = $customer->addresses()->first()) {
            $this->makeDefault($request, $next->id);
        }

        return response()->json(['deleted' => true]);
    }

    public function setDefault(Request $request, int $address): JsonResponse
    {
        $model = $this->findOwned($request, $address);
        $this->makeDefault($request, $model->id);

        return response()->json(['data' => new AddressResource($model->fresh())]);
    }

    protected function findOwned(Request $request, int $address): CustomerAddress
    {
        return $this->customer($request)->addresses()->findOrFail($address);
    }

    protected function makeDefault(Request $request, int $addressId): void
    {
        $customer = $this->customer($request);

        $customer->addresses()->update(['is_default_shipping' => false, 'is_default_billing' => false]);
        $customer->addresses()->whereKey($addressId)
            ->update(['is_default_shipping' => true, 'is_default_billing' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:40'],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:25'],
            'address_line1' => ['required', 'string', 'max:180'],
            'address_line2' => ['nullable', 'string', 'max:180'],
            'city' => ['required', 'string', 'max:80'],
            'state' => ['nullable', 'string', 'max:80'],
            'postcode' => ['nullable', 'string', 'max:12'],
            'country' => ['nullable', 'string', 'size:2'],
            'is_default_shipping' => ['boolean'],
        ];
    }
}
