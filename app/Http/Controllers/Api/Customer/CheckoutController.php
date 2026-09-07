<?php

namespace App\Http\Controllers\Api\Customer;

use App\Actions\Customer\PlaceOrder;
use App\Actions\Customer\QuoteBasket;
use App\Actions\Shipping\CheckServiceability;
use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\AddressResource;
use App\Http\Resources\Customer\OrderResource;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\PaymentMethod;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

    public function __construct(
        protected QuoteBasket $quote,
        protected PlaceOrder $placeOrder,
        protected CheckServiceability $serviceability,
    ) {}

    public function options(Request $request): JsonResponse
    {
        $cart = $this->cartFor($request);

        $addresses = $this->addressesOf($request);
        // One rule for both screens, and a choice made here is remembered on
        // the basket — so the cart's address strip agrees with this picker.
        $address = $this->selectedAddress($request, $cart, $addresses);

        $items = $cart->activeItems()
            ->with([
                'product.images', 'product.category:id,name,icon', 'product.taxClass.rates',
                // Loaded for the cash-on-delivery rule below, not for the quote.
                'product.vendor:id,name,cod_available',
                'variant',
            ])
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

        /*
        | What the couriers say about where this is going.
        |
        | Asked here rather than at `store` because the point is to answer
        | before the shopper has committed: a pincode nobody serves, or one
        | that takes prepaid but not cash, is a thing to find out on the review
        | screen and not from a failed booking two days later.
        */
        $delivery = $this->serviceability->handle((string) ($address?->postcode ?? ''));

        return response()->json([
            'addresses' => AddressResource::collection($addresses),
            'selected_address_id' => $address?->id,
            // The whole address, not just its id: the review screen draws it,
            // and looking it back up in the list is the app's work to do twice.
            'selected_address' => $address ? new AddressResource($address) : null,
            'shipping_options' => $quote['shipping_options'],
            'selected_shipping_code' => $quote['shipping_code'],
            'delivery' => [
                'serviceable' => $delivery['serviceable'],
                'cod_available' => $delivery['cod'],
                // False means no courier could be asked, not that the answer
                // was no — the app shows nothing rather than a promise nobody
                // made.
                'checked' => $delivery['checked'],
                'carrier' => $delivery['carrier'],
            ],
            'payment_methods' => self::paymentMethods(
                $this->storesRefusingCash($items),
                $this->cashRefusedByCourier($delivery),
            ),
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

        $cart = $this->cartFor($request);
        $method = PaymentMethod::where('code', $data['payment_method'])->first();

        /*
        | Cash the sellers will not take.
        |
        | The checkout screen greys the option out, but the screen is not the
        | rule: without this an app that skipped it — or an older build — could
        | place an order a seller has said they will not accept cash for, and
        | the first anybody hears of it is a courier at the door.
        */
        if (PaymentMethod::isPayOnDelivery($method?->code)) {
            $refusing = $this->storesRefusingCash($cart->activeItems()->with('product.vendor:id,name,cod_available')->get());

            if ($refusing !== []) {
                throw ValidationException::withMessages([
                    'payment_method' => count($refusing) === 1
                        ? $refusing[0].' does not take cash on delivery. Choose another way to pay.'
                        : 'Some sellers in this basket do not take cash on delivery. Choose another way to pay.',
                ]);
            }

            /*
            | And the courier's refusal, which is a different no.
            |
            | Plenty of Indian pincodes are delivered to prepaid and not COD.
            | Taking the order anyway means a booking refused days later on an
            | order the shopper believes is coming — so it is refused here,
            | where they can still choose another way to pay.
            |
            | Only ever on a *checked* answer: a courier that could not be
            | reached must not close the checkout.
            */
            $address = $customer->addresses()->find($data['address_id']);
            $delivery = $this->serviceability->handle((string) ($address?->postcode ?? ''));

            if ($reason = $this->cashRefusedByCourier($delivery)) {
                throw ValidationException::withMessages([
                    // The pincode nobody serves gets no "choose another way":
                    // there is no way to pay that makes a parcel reach it.
                    'payment_method' => $delivery['serviceable']
                        ? $reason.' Choose another way to pay.'
                        : $reason,
                ]);
            }
        }

        // Orders record the label the shopper saw, not the code — that is what
        // the seller panel and the payouts read back.
        $data['payment_method_label'] = $method?->name;
        $data['pay_on_delivery'] = PaymentMethod::isPayOnDelivery($method?->code);

        $order = $this->placeOrder->handle($customer, $cart, $data);

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
     * @param  list<string>  $refusingCash  Stores in this basket that will not
     *                                      handle cash, by name.
     * @param  string|null  $courierRefusesCash  Why the courier will not
     *                                           collect cash here, if it
     *                                           will not.
     * @return array<int, array<string, mixed>>
     */
    public static function paymentMethods(array $refusingCash = [], ?string $courierRefusesCash = null): array
    {
        return PaymentMethod::active()->orderBy('position')->get()
            ->map(function (PaymentMethod $method) use ($refusingCash, $courierRefusesCash) {
                $isCash = PaymentMethod::isPayOnDelivery($method->code);
                // Greyed out rather than missing: an option that vanishes
                // reads as a bug, where one with a reason beside it reads as
                // an answer.
                $blocked = $isCash && ($refusingCash !== [] || $courierRefusesCash !== null);

                return [
                    'code' => $method->code,
                    'name' => $method->name,
                    'description' => $method->description,
                    'icon' => $method->glyph(),
                    'is_pay_on_delivery' => $isCash,
                    'is_available' => ! $blocked,
                    'unavailable_reason' => match (true) {
                        ! $blocked => null,
                        // The seller's refusal is named first: it is the one
                        // the shopper can do something about, by shopping
                        // elsewhere.
                        count($refusingCash) === 1 => $refusingCash[0].' does not take cash on delivery',
                        $refusingCash !== [] => 'Some sellers in this basket do not take cash on delivery',
                        default => $courierRefusesCash,
                    },
                ];
            })
            ->all();
    }

    /**
     * Why the courier will not collect cash at this address, if it will not.
     *
     * Null covers both "it will" and "nobody could be asked" on purpose: an
     * unanswered question must read exactly like a yes everywhere downstream,
     * because refusing a sale on a courier's bad morning is the more expensive
     * mistake.
     *
     * @param  array{serviceable: bool, cod: bool, checked: bool, carrier: string|null}  $delivery
     */
    protected function cashRefusedByCourier(array $delivery): ?string
    {
        if (! $delivery['checked'] || $delivery['cod']) {
            return null;
        }

        return $delivery['serviceable']
            ? 'Cash on delivery is not available for this pincode.'
            : 'No courier delivers to this pincode yet.';
    }

    /**
     * The stores in this basket that will not handle cash, by name.
     *
     * One seller refusing is enough: a basket ships as one order and is paid
     * for once, so cash is off the table for the whole of it.
     *
     * @param  Collection<int, CartItem>  $items
     * @return list<string>
     */
    protected function storesRefusingCash(Collection $items): array
    {
        return array_values($items
            // `product` is non-null by the relation's own type, but a line
            // whose product was hard-deleted resolves to null all the same.
            ->map(fn (CartItem $item) => $item->product->vendor ?? null)
            ->filter()
            ->unique('id')
            ->reject(fn (Vendor $vendor) => (bool) $vendor->cod_available)
            ->map(fn (Vendor $vendor) => (string) $vendor->name)
            ->all());
    }
}
