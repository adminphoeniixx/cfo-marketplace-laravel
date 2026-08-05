<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Http\Resources\Seller\RefundResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only plus a reply, for the same reason as cancellations: the money
 * decision belongs to the marketplace.
 */
class RefundController extends Controller
{
    use ScopesToStore;

    public function index(Request $request): AnonymousResourceCollection
    {
        $refunds = $this->storeRefunds($request)
            ->when($request->string('status')->toString(), fn ($query, $status) => $query
                ->where('status', $status))
            ->latest('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return RefundResource::collection($refunds);
    }

    public function show(Request $request, int $refund): RefundResource
    {
        return new RefundResource(
            $this->storeRefunds($request)->findOrFail($refund)
        );
    }

    public function respond(Request $request, int $refund): JsonResponse
    {
        $model = $this->storeRefunds($request)->findOrFail($refund);

        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
        ]);

        $model->order->recordEvent(
            'note',
            "Seller response to refund {$model->number}",
            $data['note'],
            [
                'vendor_id' => $this->storeId($request),
                'refund_id' => $model->id,
                'source' => 'seller-api',
            ],
        );

        return response()->json(['message' => 'Response recorded.'], 201);
    }
}
