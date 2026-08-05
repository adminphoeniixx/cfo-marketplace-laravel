<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Http\Resources\Seller\PayoutResource;
use App\Models\OrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PayoutController extends Controller
{
    use ScopesToStore;

    public function index(Request $request): AnonymousResourceCollection
    {
        $payouts = $this->storePayouts($request)
            ->when($request->string('status')->toString(), fn ($query, $status) => $query
                ->where('status', $status))
            ->latest('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return PayoutResource::collection($payouts);
    }

    public function show(Request $request, int $payout): PayoutResource
    {
        return new PayoutResource($this->storePayouts($request)->findOrFail($payout));
    }

    /**
     * Money earned so far against money already paid out. The difference is
     * what the marketplace still owes this store.
     */
    public function earnings(Request $request): JsonResponse
    {
        $storeId = $this->storeId($request);

        $sold = OrderItem::query()
            ->where('order_items.vendor_id', $storeId)
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNot('orders.status', 'cancelled')
            ->selectRaw('coalesce(sum(order_items.total), 0) as gross')
            ->selectRaw('coalesce(sum(order_items.commission_amount), 0) as commission')
            ->selectRaw('coalesce(sum(order_items.vendor_earning), 0) as earning')
            ->selectRaw('count(distinct order_items.order_id) as orders')
            ->toBase()
            ->first();

        $paid = (float) $this->storePayouts($request)->where('status', 'paid')->sum('net_amount');
        $pending = (float) $this->storePayouts($request)->whereNot('status', 'paid')->sum('net_amount');
        $earned = round((float) $sold->earning, 2);

        return response()->json([
            'lifetime' => [
                'gross_sales' => round((float) $sold->gross, 2),
                'commission' => round((float) $sold->commission, 2),
                'earning' => $earned,
                'orders' => (int) $sold->orders,
            ],
            'payouts' => [
                'paid' => round($paid, 2),
                'in_progress' => round($pending, 2),
                // What has been earned but never appeared on any payout yet.
                'unsettled' => round($earned - $paid - $pending, 2),
            ],
            'commission_rate' => (float) $request->user()->vendor->commission_rate,
        ]);
    }
}
