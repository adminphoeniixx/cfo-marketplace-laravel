<?php

namespace App\Http\Controllers\Api\Customer;

use App\Actions\Customer\PlaceOrder;
use App\Actions\Customer\QuoteBasket;
use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\AddressResource;
use App\Http\Resources\Customer\OrderResource;
use App\Models\Coupon;
use App\Models\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Checkout: two endpoints, and they agree with each other.
 *
 * `options` is the review screen — addresses, delivery choices, ways to pay,
 * and what each combination costs. `store` places the order, re-running the
 * same quote server-side, so the figure the shopper agreed to is the figure
 * that gets written even if a price moved while the app sat open.
 */
class CheckoutController extends Controller
{
    use ScopesToCustomer;

    public function __construct(protected QuoteBasket $quote, protected PlaceOrder $placeOrder) {}

    public function options(Request $request): JsonResponse
    {
        $cart = $this->cartFor($request);

        $addresses = $this->addressesOf($request);
        // One rule for both screens, and a choice made here is remembered on
        // the basket — so the cart's address strip agrees with this picker.
        $address = $this->selectedAddress($request, $cart, $addresses);

        $items = $cart->activeItems()
            ->with(['product.images', 'product.category:id,name,icon', 'product.taxClass.rates', 'variant'])
            ->get();

        $coupon = $cart->coupon_code
            ? Coupon::usable()->where('code', $cart->coupon_code)->first()
            : null;

        $quote = $this->quote->handle(
            $items,
            $coupon,
            $address,
            $request->string('shipping_code')->toString() ?: null,
        );

        return response()->json([
            'addresses' => AddressResource::collection($addresses),
            'selected_address_id' => $address?->id,
            // The whole address, not just its id: the review screen draws it,
            // and looking it back up in the list is the app's work to do twice.
            'selected_address' => $address ? new AddressResource($address) : null,
            'shipping_options' => $quote['shipping_options'],
            'selected_shipping_code' => $quote['shipping_code'],
            'payment_methods' => self::paymentMethods(),
            'coupon' => $quote['coupon'],
            'totals' => $quote['totals'],
            'items' => $quote['lines'],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        $data = $request->validate([
            'address_id' => ['required', 'integer', Rule::exists('customer_addresses', 'id')
                ->where('customer_id', $customer->id)],
            'payment_method' => ['required', 'string', Rule::exists('payment_methods', 'code')
                ->where('is_active', true)],
            'shipping_code' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $method = PaymentMethod::where('code', $data['payment_method'])->first();
        // Orders record the label the shopper saw, not the code — that is what
        // the seller panel and the payouts read back.
        $data['payment_method_label'] = $method?->name;
        $data['pay_on_delivery'] = PaymentMethod::isPayOnDelivery($method?->code);

        $order = $this->placeOrder->handle($customer, $this->cartFor($request), $data);

        return response()->json([
            'data' => new OrderResource($order->load(['items', 'events'])),
        ], 201);
    }

    /**
     * Ways to pay, as the payment screen draws them.
     *
     * `icon` comes from here rather than from a map inside the app, so a
     * method the admin adds tomorrow arrives with a glyph instead of a gap,
     * and `is_pay_on_delivery` saves the app from matching on code spellings
     * to know whether to open a gateway.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function paymentMethods(): array
    {
        return PaymentMethod::active()->orderBy('position')->get()
            ->map(fn (PaymentMethod $method) => [
                'code' => $method->code,
                'name' => $method->name,
                'description' => $method->description,
                'icon' => $method->glyph(),
                'is_pay_on_delivery' => PaymentMethod::isPayOnDelivery($method->code),
            ])
            ->all();
    }
}
