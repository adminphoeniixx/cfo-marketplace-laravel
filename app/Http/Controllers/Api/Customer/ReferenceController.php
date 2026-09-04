<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Cancellation;
use App\Models\DeliveryPartner;
use App\Models\Order;
use App\Models\Refund;
use Illuminate\Http\JsonResponse;

/**
 * The lists the app needs to draw its own forms.
 *
 * Reason codes especially: the app must show exactly the reasons the API will
 * accept, and hardcoding them in the client is how those two drift apart.
 */
class ReferenceController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            // The same shape the checkout screen gets, from the same place.
            'payment_methods' => CheckoutController::paymentMethods(),
            'delivery_partners' => DeliveryPartner::where('is_active', true)->orderBy('position')->get()
                ->map(fn (DeliveryPartner $partner) => [
                    'code' => $partner->code,
                    'name' => $partner->name,
                    'support_phone' => $partner->support_phone,
                    // The pattern, with `{tracking}` still in it: the app can
                    // show where a link would go before there is a consignment.
                    'tracking_url' => $partner->tracking_url,
                ]),
            'cancellation_reasons' => collect(Cancellation::REASONS)
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                ->values(),
            'refund_reasons' => collect(Refund::REASONS)
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                ->values(),
            'refund_methods' => collect(Refund::METHODS)
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                ->values(),
            'return_window_days' => Order::RETURN_WINDOW_DAYS,
        ]);
    }
}
