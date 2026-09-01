<?php

namespace App\Http\Controllers\Api\Customer;

use App\Models\Cancellation;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Refund;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The single place the customer API decides "whose data is this".
 *
 * Same contract as the seller API's `ScopesToStore`: every list starts from a
 * builder here and every lookup goes through a `findOwned*`, so someone else's
 * order is a 404 rather than a leak. Nothing reads an id of a person out of the
 * request — the shopper always comes from the token.
 */
trait ScopesToCustomer
{
    protected function customer(Request $request): Customer
    {
        // Asked of the guard that actually authenticated the request. Plain
        // `user()` reads the session guard, which knows only about staff — on
        // a token request it is both wrong at runtime and wrong to the type
        // system.
        $user = $request->user('sanctum');

        // The `customer` middleware has already refused anything that is not a
        // shopper. Repeating the check here is what makes that guarantee true
        // for the type system as well as at runtime — and it is the reason
        // nothing below has to second-guess who it is serving.
        if (! $user instanceof Customer) {
            abort(403, 'This endpoint is for customer accounts.');
        }

        return $user;
    }

    /**
     * @return Builder<Order>
     */
    protected function customerOrders(Request $request): Builder
    {
        return Order::query()->where('customer_id', $this->customer($request)->id);
    }

    protected function findOwnedOrder(Request $request, string $number): Order
    {
        return $this->customerOrders($request)
            ->with(['items', 'events'])
            ->where('number', $this->normaliseNumber($number))
            ->firstOrFail();
    }

    /**
     * @return Builder<Cancellation>
     */
    protected function customerCancellations(Request $request): Builder
    {
        return Cancellation::query()
            ->where('customer_id', $this->customer($request)->id)
            ->with(['order:id,number', 'items.orderItem:id,name,sku,image_path']);
    }

    /**
     * @return Builder<Refund>
     */
    protected function customerRefunds(Request $request): Builder
    {
        return Refund::query()
            ->where('customer_id', $this->customer($request)->id)
            ->with(['order:id,number', 'items.orderItem:id,name,sku,image_path']);
    }

    /**
     * The shopper's open basket, created on first touch.
     *
     * A signed-out device may carry a `X-Cart-Token`; the first authenticated
     * call folds that basket into the customer's own, which is what makes
     * "browse, fill a cart, then sign in" work without losing anything.
     */
    protected function cartFor(Request $request): Cart
    {
        $customer = $this->customer($request);
        $cart = Cart::firstOrCreate(['customer_id' => $customer->id]);

        $guestToken = $request->header('X-Cart-Token');

        if ($guestToken) {
            $guest = Cart::where('token', $guestToken)->whereNull('customer_id')->first();
            $guest?->items->each(function ($item) use ($cart) {
                $existing = $cart->items()
                    ->where('product_id', $item->product_id)
                    ->where('product_variant_id', $item->product_variant_id)
                    ->first();

                $existing
                    ? $existing->increment('quantity', $item->quantity)
                    : $cart->items()->create($item->only(['product_id', 'product_variant_id', 'quantity', 'saved_for_later']));
            });
            $guest?->delete();
        }

        return $cart;
    }

    /**
     * Orders are quoted with a `#` in every screen; accept both spellings.
     */
    protected function normaliseNumber(string $number): string
    {
        return str_starts_with($number, '#') ? $number : '#'.$number;
    }

    protected function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 20), 1), 60);
    }
}
