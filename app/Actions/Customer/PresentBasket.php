<?php

namespace App\Actions\Customer;

use App\Http\Resources\Customer\CartResource;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\CustomerAddress;

/**
 * The basket, priced and dressed for the app.
 *
 * `QuoteBasket` says what a basket costs; this says what the cart screen is
 * sent. It exists because three places need that answer — the cart endpoints,
 * and a reorder, which has to hand back the basket it just changed rather than
 * making the app fetch it again to find out what happened.
 */
class PresentBasket
{
    public function __construct(protected QuoteBasket $quote) {}

    public function handle(Cart $cart, ?CustomerAddress $address = null, ?string $shippingCode = null): CartResource
    {
        $cart->load(['items.product.images', 'items.product.category:id,name,icon', 'items.variant']);
        $address ??= $this->addressFor($cart);

        return new CartResource(
            $cart,
            $this->quote($cart, $address, $shippingCode),
            $address,
            // What `GET /cart/coupons` would list. The cart's coupon row said
            // "3 offers available" from a constant in the app, which was true
            // of the demo data and nothing else.
            Coupon::usable()->count(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function quote(Cart $cart, ?CustomerAddress $address = null, ?string $shippingCode = null): array
    {
        $items = $cart->activeItems()
            // `category` is loaded for the emoji fallback on lines with no
            // photograph; `taxClass.rates` for the tax the quote charges.
            ->with(['product.images', 'product.category:id,name,icon', 'product.taxClass.rates', 'variant'])
            ->get();

        $coupon = $cart->coupon_code
            ? Coupon::usable()->where('code', $cart->coupon_code)->first()
            : null;

        return $this->quote->handle($items, $coupon, $address ?? $this->addressFor($cart), $shippingCode);
    }

    /**
     * The address a basket is priced against when nobody has named one.
     *
     * The same order `ScopesToCustomer::selectedAddress()` uses, resolved from
     * the basket alone — so a quote taken without a request in hand still
     * prices against the address the shopper is looking at.
     */
    public function addressFor(Cart $cart): ?CustomerAddress
    {
        $customer = $cart->customer_id ? $cart->customer : null;

        if (! $customer) {
            return null;
        }

        return $customer->addresses()
            ->when(
                $cart->selected_address_id,
                fn ($query, int $id) => $query->orderByRaw('case when id = ? then 0 else 1 end', [$id]),
            )
            ->orderByDesc('is_default_shipping')
            ->orderBy('id')
            ->first();
    }
}
