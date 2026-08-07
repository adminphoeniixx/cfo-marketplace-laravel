<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Zones are the marketplace's and read-only. Rates inside them belong to a
 * store, so a seller can price their own delivery per zone.
 *
 * A rate with a null vendor_id is the marketplace's own fallback — it shows up
 * here as `is_marketplace` and cannot be edited or deleted by a seller.
 */
class ShippingController extends Controller
{
    use ScopesToStore;

    public function zones(): JsonResponse
    {
        return response()->json([
            'data' => ShippingZone::where('is_active', true)
                ->orderBy('position')->orderBy('name')
                ->get(['id', 'name', 'description', 'countries', 'states']),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $storeId = $this->storeId($request);

        $rates = ShippingRate::with('zone:id,name')
            ->where(fn ($q) => $q->where('vendor_id', $storeId)->orWhereNull('vendor_id'))
            ->when($request->integer('zone'), fn ($q, $zone) => $q->where('shipping_zone_id', $zone))
            ->orderBy('shipping_zone_id')->orderBy('position')
            ->get()
            ->map(fn (ShippingRate $r) => $this->shape($r, $storeId));

        return response()->json(['data' => $rates->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $rate = ShippingRate::create([
            ...$data,
            'vendor_id' => $this->storeId($request),
        ]);

        return response()->json(
            ['data' => $this->shape($rate->load('zone:id,name'), $this->storeId($request))],
            201,
        );
    }

    public function update(Request $request, int $rate): JsonResponse
    {
        $model = $this->findOwnedRate($request, $rate);

        $model->update($this->validated($request, $model));

        return response()->json([
            'data' => $this->shape($model->fresh()->load('zone:id,name'), $this->storeId($request)),
        ]);
    }

    public function destroy(Request $request, int $rate): JsonResponse
    {
        $this->findOwnedRate($request, $rate)->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Marketplace fallbacks are visible but not yours — a 404 either way.
     */
    private function findOwnedRate(Request $request, int $id): ShippingRate
    {
        return ShippingRate::where('vendor_id', $this->storeId($request))->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?ShippingRate $rate = null): array
    {
        return $request->validate([
            'shipping_zone_id' => ['required', 'integer', 'exists:shipping_zones,id'],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(ShippingRate::TYPES)],
            'rate' => ['required', 'numeric', 'min:0', 'max:999999'],
            'per_item_rate' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'per_kg_rate' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0'],
            'max_order_amount' => ['nullable', 'numeric', 'min:0'],
            'free_above_amount' => ['nullable', 'numeric', 'min:0'],
            'delivery_days_min' => ['nullable', 'integer', 'min:0', 'max:365'],
            'delivery_days_max' => ['nullable', 'integer', 'min:0', 'max:365'],
            'is_active' => ['boolean'],
            'position' => ['integer', 'min:0', 'max:999'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(ShippingRate $r, int $storeId): array
    {
        return [
            'id' => $r->id,
            'zone' => ['id' => $r->shipping_zone_id, 'name' => $r->zone?->name],
            'name' => $r->name,
            'type' => $r->type,
            'rate' => (float) $r->rate,
            'per_item_rate' => (float) $r->per_item_rate,
            'per_kg_rate' => (float) $r->per_kg_rate,
            'min_order_amount' => $r->min_order_amount !== null ? (float) $r->min_order_amount : null,
            'max_order_amount' => $r->max_order_amount !== null ? (float) $r->max_order_amount : null,
            'free_above_amount' => $r->free_above_amount !== null ? (float) $r->free_above_amount : null,
            'delivery_days' => ['min' => $r->delivery_days_min, 'max' => $r->delivery_days_max],
            'is_active' => (bool) $r->is_active,
            'position' => (int) $r->position,
            // Marketplace-wide fallback: shown so the seller understands what
            // applies when they have set nothing, but not theirs to change.
            'is_marketplace' => $r->vendor_id === null,
            'editable' => $r->vendor_id === $storeId,
        ];
    }
}
