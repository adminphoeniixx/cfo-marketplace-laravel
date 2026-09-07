<?php

namespace App\Http\Controllers\Api\Customer;

use App\Actions\Shipping\CheckServiceability;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Do you deliver to my pincode?"
 *
 * Asked on a product page, long before there is a basket or an address to hang
 * the question off — which is why this is open and takes a bare pincode rather
 * than living on the checkout.
 *
 * The answer is deliberately shaped for a message rather than a boolean: a
 * pincode that takes prepaid but not cash is the common case in India and the
 * one a shopper most needs telling about, because they will otherwise choose
 * cash at the checkout and have the order fail at the courier.
 */
class ServiceabilityController extends Controller
{
    public function __construct(protected CheckServiceability $check) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pincode' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        $answer = $this->check->handle($data['pincode']);

        return response()->json([
            'pincode' => $data['pincode'],
            'serviceable' => $answer['serviceable'],
            'cod_available' => $answer['cod'],
            'prepaid_available' => $answer['prepaid'],
            // False means nobody could be asked. The app should say nothing
            // rather than promise a delivery on an answer that was never
            // given — see `CheckServiceability`, which fails open on purpose.
            'checked' => $answer['checked'],
            'carrier' => $answer['carrier'],
            'message' => $this->message($answer),
        ]);
    }

    /**
     * @param  array{serviceable: bool, cod: bool, prepaid: bool, pickup: bool, checked: bool, carrier: string|null}  $answer
     */
    protected function message(array $answer): ?string
    {
        if (! $answer['checked']) {
            return null;
        }

        if (! $answer['serviceable']) {
            return 'No courier delivers here yet.';
        }

        return $answer['cod']
            ? 'Delivered here, cash on delivery available.'
            : 'Delivered here. Cash on delivery is not available for this pincode.';
    }
}
