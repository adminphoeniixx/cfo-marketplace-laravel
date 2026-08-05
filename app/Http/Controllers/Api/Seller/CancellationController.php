<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Http\Resources\Seller\CancellationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only plus a reply. Approving or rejecting a cancellation is the
 * marketplace's call — a seller states their case, staff decide.
 */
class CancellationController extends Controller
{
    use ScopesToStore;

    public function index(Request $request): AnonymousResourceCollection
    {
        $cancellations = $this->storeCancellations($request)
            ->when($request->string('status')->toString(), fn ($query, $status) => $query
                ->where('status', $status))
            ->latest('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return CancellationResource::collection($cancellations);
    }

    public function show(Request $request, int $cancellation): CancellationResource
    {
        return new CancellationResource(
            $this->storeCancellations($request)->findOrFail($cancellation)
        );
    }

    /**
     * Add the seller's side of the story to the order timeline.
     */
    public function respond(Request $request, int $cancellation): JsonResponse
    {
        $model = $this->storeCancellations($request)->findOrFail($cancellation);

        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
        ]);

        $model->order->recordEvent(
            'note',
            "Seller response to cancellation {$model->number}",
            $data['note'],
            [
                'vendor_id' => $this->storeId($request),
                'cancellation_id' => $model->id,
                'source' => 'seller-api',
            ],
        );

        return response()->json(['message' => 'Response recorded.'], 201);
    }
}
