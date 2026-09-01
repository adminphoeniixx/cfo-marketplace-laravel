<?php

namespace App\Http\Controllers\Api\Customer;

use App\Actions\Customer\QuoteBasket;
use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\CartResource;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The basket.
 *
 * Every route here answers with the whole basket, priced — never with just the
 * line that changed. Adding something moves the total, the coupon's worth, and
 * whether delivery is still free; sending back one line would leave the app
 * guessing at the rest.
 */
class CartController extends Controller
{
    use ScopesToCustomer;

    public function __construct(protected QuoteBasket $quote) {}

    public function show(Request $request): JsonResponse
    {
        return $this->respond($request, $this->cartFor($request));
    }

    public function addItem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'product_variant_id' => ['nullable', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $product = Product::with('variants')->where('status', 'active')->find((int) $data['product_id']);

        if (! $product) {
            throw ValidationException::withMessages(['product_id' => 'That product is not on sale.']);
        }

        $variant = isset($data['product_variant_id'])
            ? $product->variants->firstWhere('id', (int) $data['product_variant_id'])
            : null;

        if (isset($data['product_variant_id']) && ! $variant) {
            throw ValidationException::withMessages([
                'product_variant_id' => 'That option does not belong to this product.',
            ]);
        }

        // A product built out of variants cannot be bought as itself: the app
        // has to say which size, or the seller has nothing to pack.
        if ($product->type === 'variable' && ! $variant && $product->variants->isNotEmpty()) {
            throw ValidationException::withMessages([
                'product_variant_id' => 'Choose an option before adding this to your cart.',
            ]);
        }

        $cart = $this->cartFor($request);
        $quantity = (int) ($data['quantity'] ?? 1);

        $line = $cart->items()
            ->where('product_id', $product->id)
            ->where('product_variant_id', $variant?->id)
            ->first();

        $line
            ? $line->update(['quantity' => min($line->quantity + $quantity, 10), 'saved_for_later' => false])
            : $cart->items()->create([
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'quantity' => $quantity,
            ]);

        return $this->respond($request, $cart);
    }

    public function updateItem(Request $request, int $item): JsonResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:10'],
        ]);

        $cart = $this->cartFor($request);
        $line = $this->lineOf($cart, $item);

        $line->update(['quantity' => $data['quantity']]);

        return $this->respond($request, $cart);
    }

    public function removeItem(Request $request, int $item): JsonResponse
    {
        $cart = $this->cartFor($request);
        $this->lineOf($cart, $item)->delete();

        return $this->respond($request, $cart);
    }

    /**
     * Park a line without losing the chosen size or colour — which is what
     * moving it to the wishlist would do.
     */
    public function saveForLater(Request $request, int $item): JsonResponse
    {
        $cart = $this->cartFor($request);
        $this->lineOf($cart, $item)->update(['saved_for_later' => true]);

        return $this->respond($request, $cart);
    }

    public function moveToCart(Request $request, int $item): JsonResponse
    {
        $cart = $this->cartFor($request);
        $this->lineOf($cart, $item)->update(['saved_for_later' => false]);

        return $this->respond($request, $cart);
    }

    public function applyCoupon(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:60']]);

        $cart = $this->cartFor($request);
        $code = mb_strtoupper(trim($data['code']));
        $coupon = Coupon::usable()->where('code', $code)->first();

        if (! $coupon) {
            throw ValidationException::withMessages(['code' => "$code isn't a valid code."]);
        }

        $subtotal = (float) $this->quoteFor($cart)['totals']['subtotal'];

        if ($rejection = $coupon->rejectionFor($subtotal)) {
            throw ValidationException::withMessages(['code' => $rejection]);
        }

        $cart->update(['coupon_code' => $coupon->code]);

        return $this->respond($request, $cart);
    }

    public function removeCoupon(Request $request): JsonResponse
    {
        $cart = $this->cartFor($request);
        $cart->update(['coupon_code' => null]);

        return $this->respond($request, $cart);
    }

    public function clear(Request $request): JsonResponse
    {
        $cart = $this->cartFor($request);
        $cart->activeItems()->delete();
        $cart->update(['coupon_code' => null]);

        return $this->respond($request, $cart);
    }

    /**
     * Codes the shopper could use, each already judged against this basket.
     */
    public function coupons(Request $request): JsonResponse
    {
        $cart = $this->cartFor($request);
        $subtotal = (float) $this->quoteFor($cart)['totals']['subtotal'];

        return response()->json([
            'data' => Coupon::usable()->orderBy('min_spend')->get()->map(fn (Coupon $coupon) => [
                'code' => $coupon->code,
                'description' => $coupon->description,
                'type' => $coupon->type,
                'value' => (float) $coupon->value,
                'min_spend' => (float) $coupon->min_spend,
                'discount' => $coupon->discountOn($subtotal),
                'usable' => $coupon->rejectionFor($subtotal) === null,
                'reason' => $coupon->rejectionFor($subtotal),
                'applied' => $cart->coupon_code === $coupon->code,
            ]),
        ]);
    }

    protected function lineOf(Cart $cart, int $item): CartItem
    {
        return $cart->items()->findOrFail($item);
    }

    /**
     * @return array<string, mixed>
     */
    protected function quoteFor(Cart $cart, ?string $shippingCode = null): array
    {
        $items = $cart->activeItems()
            ->with(['product.images', 'product.taxClass.rates', 'variant'])
            ->get();

        $coupon = $cart->coupon_code
            ? Coupon::usable()->where('code', $cart->coupon_code)->first()
            : null;

        $customer = $cart->customer_id ? $cart->customer : null;
        $address = $customer?->addresses()->orderByDesc('is_default_shipping')->first();

        return $this->quote->handle($items, $coupon, $address, $shippingCode);
    }

    protected function respond(Request $request, Cart $cart): JsonResponse
    {
        $cart->load(['items.product.images', 'items.variant']);

        return response()->json([
            'data' => new CartResource($cart, $this->quoteFor($cart, $request->string('shipping_code')->toString() ?: null)),
        ]);
    }
}
