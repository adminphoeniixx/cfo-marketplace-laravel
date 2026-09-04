<?php

namespace App\Actions\Customer;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Vendor;
use App\Notifications\LowStockReached;
use App\Notifications\OrderPlaced;
use App\Services\Eta;
use App\Services\Notifier;
use App\Services\Razorpay;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Checkout.
 *
 * The shopper's basket becomes one order, however many stores it spans: each
 * line keeps its own `vendor_id`, its own commission and its own earning, and
 * the sellers each see only their own half. `QuoteBasket` has already said what
 * it costs — this re-runs that quote at the moment of writing rather than
 * trusting the client, so a price that moved while the app sat open is caught
 * here instead of being sold at the stale figure.
 */
class PlaceOrder
{
    public function __construct(protected QuoteBasket $quote) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function handle(Customer $customer, Cart $cart, array $data): Order
    {
        /** @var Collection<int, CartItem> $items */
        $items = $cart->activeItems()
            ->with(['product.images', 'product.taxClass.rates', 'product.vendor', 'variant'])
            ->get();

        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['cart' => 'Your cart is empty.']);
        }

        $address = $customer->addresses()->findOrFail((int) $data['address_id']);
        $coupon = $cart->coupon_code
            ? Coupon::usable()->where('code', $cart->coupon_code)->first()
            : null;

        $quote = $this->quote->handle($items, $coupon, $address, $data['shipping_code'] ?? null);

        $this->assertSellable($quote['lines']);

        $order = DB::transaction(fn () => $this->persist($customer, $cart, $address, $quote, $coupon, $data));

        $this->notify($order);

        return $order;
    }

    /**
     * Nothing is sold that cannot be shipped. The message names the line, so
     * the app can point at the row that has to change.
     *
     * @param  list<array<string, mixed>>  $lines
     *
     * @throws ValidationException
     */
    protected function assertSellable(array $lines): void
    {
        foreach ($lines as $line) {
            if (! $line['in_stock']) {
                throw ValidationException::withMessages([
                    'items' => "Only {$line['available']} left of {$line['name']}. Reduce the quantity to continue.",
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $quote
     * @param  array<string, mixed>  $data
     */
    protected function persist(
        Customer $customer,
        Cart $cart,
        CustomerAddress $address,
        array $quote,
        ?Coupon $coupon,
        array $data,
    ): Order {
        // Whether the money is already in decides `payment_status`; the caller
        // works that out from the chosen method, because the codes are the
        // admin panel's to name and 'cod' is not the only spelling.
        $payOnDelivery = (bool) ($data['pay_on_delivery'] ?? false);

        /*
        | With a gateway configured, money is not in until Razorpay says it is:
        | the order is written `pending` and waits for `POST /payments/verify`
        | or the webhook. With no gateway the marketplace keeps its old
        | behaviour and treats anything but cash on delivery as captured, which
        | is what lets the demo and the test suite run without credentials.
        */
        $awaitingPayment = ! $payOnDelivery && Razorpay::enabled();
        $paid = ! $payOnDelivery && ! $awaitingPayment;

        $addressPayload = $this->addressPayload($address, $customer);
        // The window the shopper was promised, frozen at the moment they
        // agreed to it — not recomputed later off rates that may have moved.
        $eta = Eta::window(...$this->deliveryDays($quote));

        $order = Order::create([
            'number' => Order::nextNumber(),
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'phone' => $address->phone ?? $customer->phone,
            // Nothing is packed against money that has not arrived; the
            // capture moves this on to `processing`.
            'status' => $awaitingPayment ? 'pending' : 'processing',
            'payment_status' => $paid ? 'paid' : 'pending',
            'fulfillment_status' => 'unfulfilled',
            'currency' => 'INR',
            'payment_method' => $data['payment_method_label'] ?? $data['payment_method'] ?? null,
            'shipping_method' => $quote['shipping_method'],
            'coupon_code' => $coupon?->code,
            'billing_address' => $addressPayload,
            'shipping_address' => $addressPayload,
            'customer_note' => $data['note'] ?? null,
            'placed_at' => now(),
            'paid_at' => $paid ? now() : null,
            'eta_min_at' => $eta[0] ?? null,
            'eta_max_at' => $eta[1] ?? null,
        ]);

        $commissionTotal = 0.0;
        $vendorIds = array_values(array_unique(array_filter(
            array_column($quote['lines'], 'vendor_id')
        )));

        $rates = Vendor::query()->whereIn('id', $vendorIds)->pluck('commission_rate', 'id');

        $subtotal = (float) $quote['totals']['subtotal'];
        $discount = (float) $quote['totals']['discount_total'];
        $ratio = $subtotal > 0 ? max($subtotal - $discount, 0) / $subtotal : 0;

        foreach ($quote['lines'] as $line) {
            $commissionRate = (float) ($rates[$line['vendor_id']] ?? 0);
            $lineTotal = (float) $line['total'];
            // The discount is spread across the lines it came off, so a
            // seller's commission is charged on what the basket actually paid
            // them — not on the pre-coupon price.
            $netTotal = round($lineTotal * $ratio, 2);
            $commission = round($netTotal * $commissionRate / 100, 2);
            $tax = round($netTotal * (float) $line['tax_rate'] / 100, 2);

            $order->items()->create([
                'product_id' => $line['product_id'],
                'product_variant_id' => $line['product_variant_id'],
                'vendor_id' => $line['vendor_id'],
                'name' => $line['name'],
                'sku' => $line['sku'],
                'image_path' => $line['image'],
                'options' => $line['options'],
                'unit_price' => $line['unit_price'],
                'quantity' => $line['quantity'],
                'discount_amount' => round($lineTotal - $netTotal, 2),
                'tax_rate' => $line['tax_rate'],
                'tax_amount' => $tax,
                'total' => $lineTotal,
                'commission_rate' => $commissionRate,
                'commission_amount' => $commission,
                'vendor_earning' => round($netTotal - $commission, 2),
            ]);

            $this->decrementStock($line);
            $commissionTotal += $commission;
        }

        $order->update([
            'subtotal' => $subtotal,
            'discount_total' => $discount,
            'tax_total' => $quote['totals']['tax_total'],
            'shipping_total' => $quote['totals']['shipping_total'],
            'commission_total' => round($commissionTotal, 2),
            'grand_total' => $quote['totals']['grand_total'],
        ]);

        $order->recordEvent(
            'status',
            'Order placed',
            "Placed by {$customer->name} from the shopper app.",
            ['source' => 'customer-api'],
        );

        $coupon?->increment('used_count');

        // The basket is spent: the bought lines go, anything saved for later
        // stays behind for next time.
        $cart->activeItems()->delete();
        $cart->update(['coupon_code' => null]);

        $customer->refreshOrderStats();

        return $order;
    }

    /**
     * The chosen delivery option's day count, or two nulls when the option
     * carries none.
     *
     * @param  array<string, mixed>  $quote
     * @return array{0: int|null, 1: int|null}
     */
    protected function deliveryDays(array $quote): array
    {
        foreach ((array) $quote['shipping_options'] as $option) {
            if (($option['code'] ?? null) === ($quote['shipping_code'] ?? null)) {
                return [$option['delivery_days_min'] ?? null, $option['delivery_days_max'] ?? null];
            }
        }

        return [null, null];
    }

    /**
     * @param  array<string, mixed>  $line
     */
    protected function decrementStock(array $line): void
    {
        $product = Product::find((int) $line['product_id']);

        if (! $product?->track_inventory) {
            return;
        }

        $variant = $line['product_variant_id'] ? ProductVariant::find((int) $line['product_variant_id']) : null;

        $variant
            ? $variant->decrement('stock_quantity', $line['quantity'])
            : $product->decrement('stock_quantity', $line['quantity']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function addressPayload(CustomerAddress $address, Customer $customer): array
    {
        return [
            'first_name' => $address->first_name,
            'last_name' => $address->last_name,
            'address_line1' => $address->address_line1,
            'address_line2' => $address->address_line2,
            'city' => $address->city,
            'state' => $address->state,
            'postcode' => $address->postcode,
            'country' => $address->country ?? 'IN',
            'phone' => $address->phone ?? $customer->phone,
        ];
    }

    protected function notify(Order $order): void
    {
        $order->load('items', 'customer');

        // One copy per store: on a shared basket a seller must be told their
        // own share, never the shopper's total. No actor — the shopper is not
        // a staff login, so nobody is skipped as "the person who did this".
        Notifier::sendPerStore(
            (new OrderPlaced($order))->vendorIds(),
            fn (?int $storeId) => new OrderPlaced($order, $storeId),
        );

        Product::whereIn('id', $order->items->pluck('product_id')->filter())
            ->where('track_inventory', true)
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->get()
            ->each(fn (Product $product) => Notifier::send(new LowStockReached($product)));
    }
}
