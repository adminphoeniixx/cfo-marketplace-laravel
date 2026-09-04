<?php

use App\Models\Cancellation;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CustomerDeviceToken;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\Refund;
use App\Models\Vendor;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;

/*
| Every shopper endpoint, called once, with data behind it.
|
| The rest of the suite proves each screen's rules. This file answers the
| question an app developer asks on day one and nobody had a single answer to:
| is the endpoint there, and does it come back with something rather than an
| error? One call per route, all of them, with a fixture that makes each one
| succeed.
|
| Two things keep it honest. Each call runs inside its own savepoint, so a
| DELETE never decides what the next call sees and the order of the list means
| nothing. And the last test reads the router itself: a route added without a
| call here fails, so this cannot quietly fall behind the API.
*/

beforeEach(function () {
    // With credentials, so the payment endpoints answer for real rather than
    // falling back to "assume it is paid".
    config([
        'services.razorpay.key' => 'rzp_test_key',
        'services.razorpay.secret' => 'rzp_test_secret',
        'services.razorpay.webhook_secret' => 'whsec_test',
    ]);

    Http::fake([
        'api.razorpay.com/v1/orders' => Http::response([
            'id' => 'order_SMOKE1', 'amount' => 100000, 'currency' => 'INR',
        ]),
        'api.razorpay.com/v1/payments/*' => Http::response([
            'id' => 'pay_SMOKE1', 'method' => 'upi', 'status' => 'captured',
        ]),
    ]);

    $this->fix = shopperFixture();
});

/**
 * One shopper with a life: an address, a basket, orders in every state the app
 * has a screen for, a rating, a return, a cancellation and a notification.
 *
 * @return array<string, mixed>
 */
function shopperFixture(): array
{
    $customer = actingAsCustomer([
        'first_name' => 'Priya',
        'last_name' => 'Nair',
        'email' => 'priya@example.com',
        'phone' => '9876543210',
    ]);
    $customer->forceFill(['password' => 'shopper-password'])->save();

    // A second device, so `devices.revoke` has something to revoke that is not
    // the token this test is holding.
    $otherToken = $customer->createToken('old tablet');

    $address = $customer->addresses()->create([
        'label' => 'Home',
        'first_name' => 'Priya',
        'last_name' => 'Nair',
        'address_line1' => '42 Beach Road',
        'city' => 'Chennai',
        'state' => 'Tamil Nadu',
        'postcode' => '600090',
        'country' => 'IN',
        'phone' => '9876543210',
        'is_default_shipping' => true,
    ]);

    // A second one, because the last address standing cannot be deleted.
    $customer->addresses()->create([
        'label' => 'Work',
        'first_name' => 'Priya',
        'address_line1' => '9 Mount Road',
        'city' => 'Chennai',
        'postcode' => '600002',
    ]);

    PaymentMethod::query()->whereNotIn('code', ['upi', 'cash-on-delivery'])->update(['is_active' => false]);

    $vendor = Vendor::factory()->create(['status' => 'approved', 'phone' => '9000000001']);
    $product = sellableProduct(['name' => 'Kashmiri wool shawl', 'price' => 1000], $vendor);
    $second = sellableProduct(['name' => 'Brass table lamp', 'price' => 1500], $vendor);

    $coupon = Coupon::factory()->create(['code' => 'SMOKE100', 'min_spend' => 0]);

    // The basket, through the API — the same path the app takes, so the cart
    // rows are shaped the way the endpoints below expect them.
    test()->postJson(route('api.customer.cart.items.store'), ['product_id' => $product->id, 'quantity' => 2]);
    test()->postJson(route('api.customer.cart.items.store'), ['product_id' => $second->id]);
    $cart = $customer->cart()->firstOrFail();
    $line = $cart->items()->where('product_id', $product->id)->firstOrFail();
    $savedLine = $cart->items()->where('product_id', $second->id)->firstOrFail();
    $savedLine->update(['saved_for_later' => true]);
    $cart->update(['coupon_code' => $coupon->code, 'selected_address_id' => $address->id]);

    $delivered = shopperOrder($customer, $vendor, $product, [
        'status' => 'completed',
        'payment_status' => 'paid',
        'fulfillment_status' => 'fulfilled',
        'payment_method' => 'UPI',
        'transaction_id' => 'pay_OLD1',
        'carrier' => 'shiprocket',
        'tracking_number' => 'TRK-SMOKE-1',
        'delivered_at' => now()->subDay(),
        'shipped_at' => now()->subDays(3),
        'eta_min_at' => now()->subDays(2)->toDateString(),
        'eta_max_at' => now()->subDay()->toDateString(),
    ]);

    // Still cancellable: nothing has been packed yet.
    $open = shopperOrder($customer, $vendor, $product, [
        'status' => 'pending',
        'payment_status' => 'pending',
        'payment_method' => 'Cash on Delivery',
        'paid_at' => null,
    ]);

    // Waiting on a gateway, with an intent already opened against it.
    $unpaid = shopperOrder($customer, $vendor, $product, [
        'status' => 'pending',
        'payment_status' => 'pending',
        'payment_method' => 'UPI',
        'paid_at' => null,
        'grand_total' => 1180,
    ]);

    $payment = Payment::create([
        'order_id' => $unpaid->id,
        'customer_id' => $customer->id,
        'gateway' => 'razorpay',
        'gateway_order_id' => 'order_SMOKE1',
        'status' => 'created',
        'amount' => $unpaid->grand_total,
        'currency' => 'INR',
    ]);

    $review = ProductReview::create([
        'product_id' => $product->id,
        'customer_id' => $customer->id,
        'order_id' => $delivered->id,
        'rating' => 4,
        'title' => 'Warm and light',
        'body' => 'Exactly as photographed.',
        'is_verified' => true,
        'status' => 'published',
    ]);

    $cancellation = Cancellation::create([
        'number' => Cancellation::nextNumber(),
        'order_id' => $open->id,
        'customer_id' => $customer->id,
        'reason' => 'ordered_by_mistake',
        'restock' => true,
        'refund_requested' => false,
        'requested_by' => 'customer',
        'status' => 'pending',
        'scope' => 'full',
        'total_amount' => 1180,
    ]);

    $refund = Refund::create([
        'number' => Refund::nextNumber(),
        'order_id' => $delivered->id,
        'customer_id' => $customer->id,
        'reason' => 'damaged',
        'method' => 'original',
        'restock' => true,
        'shipping_amount' => 0,
        'adjustment_amount' => 0,
        'total_amount' => 1000,
        'status' => 'pending',
    ]);

    $customer->notify(new class extends Notification
    {
        /** @return array<int, string> */
        public function via(object $notifiable): array
        {
            return ['database'];
        }

        /** @return array<string, mixed> */
        public function toArray(object $notifiable): array
        {
            return ['title' => 'Your order shipped', 'body' => 'TRK-SMOKE-1', 'kind' => 'order'];
        }
    });

    CustomerDeviceToken::remember($customer, 'fcm-smoke-token', 'android', 'Pixel');

    $customer->wishlistItems()->create(['product_id' => $second->id]);

    return [
        'customer' => $customer,
        'address' => $address,
        'vendor' => $vendor,
        'product' => $product,
        'second' => $second,
        'coupon' => $coupon,
        'line' => $line,
        'saved_line' => $savedLine,
        'delivered' => $delivered,
        'open' => $open,
        'unpaid' => $unpaid,
        'payment' => $payment,
        'review' => $review,
        'cancellation' => $cancellation,
        'refund' => $refund,
        'notification' => $customer->notifications()->firstOrFail(),
        'other_token_id' => $otherToken->accessToken->id,
    ];
}

/**
 * An order of this shopper's, carrying one line of the given product.
 *
 * @param  array<string, mixed>  $attributes
 */
function shopperOrder(Customer $customer, Vendor $vendor, Product $product, array $attributes): Order
{
    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'email' => $customer->email,
        'subtotal' => 1000,
        'tax_total' => 180,
        'shipping_total' => 0,
        'grand_total' => 1180,
        ...$attributes,
    ]);

    $order->items()->create([
        'product_id' => $product->id,
        'vendor_id' => $vendor->id,
        'name' => $product->name,
        'sku' => 'SKU-'.$order->id,
        'unit_price' => 1000,
        'quantity' => 1,
        'tax_amount' => 180,
        'total' => 1000,
        'commission_rate' => 10,
        'commission_amount' => 100,
        'vendor_earning' => 900,
    ]);

    return $order->load('items');
}

/** The order number as the app puts it in a URL. */
function pathNumber(Order $order): string
{
    return ltrim($order->number, '#');
}

/**
 * Every call, keyed by route name: [method, url, payload].
 *
 * @param  array<string, mixed>  $f
 *                                   The fourth slot is the status the app should expect; 200 where it is left
 *                                   off.
 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3?: int}>
 */
function shopperCalls(array $f): array
{
    return [
        // Signing in, and the devices holding a token.
        'api.customer.auth.otp' => ['POST', route('api.customer.auth.otp'), ['phone' => '9876543210']],
        'api.customer.auth.otp.verify' => ['POST', route('api.customer.auth.otp.verify'), []],
        'api.customer.auth.register' => ['POST', route('api.customer.auth.register'), [
            'first_name' => 'Anaya', 'email' => 'anaya@example.com',
            'password' => 'a-good-password', 'password_confirmation' => 'a-good-password',
            'device_name' => 'Pixel 8',
        ], 201],
        'api.customer.auth.login' => ['POST', route('api.customer.auth.login'), [
            'email' => 'priya@example.com', 'password' => 'shopper-password', 'device_name' => 'Pixel 8',
        ]],
        'api.customer.auth.forgot-password' => ['POST', route('api.customer.auth.forgot-password'), [
            'email' => 'priya@example.com',
        ]],
        'api.customer.auth.reset-password' => ['POST', route('api.customer.auth.reset-password'), []],
        'api.customer.auth.refresh' => ['POST', route('api.customer.auth.refresh'), []],
        'api.customer.auth.devices' => ['GET', route('api.customer.auth.devices'), []],
        'api.customer.auth.devices.revoke' => ['DELETE', route('api.customer.auth.devices.revoke', $f['other_token_id']), []],
        'api.customer.auth.logout' => ['POST', route('api.customer.auth.logout'), []],
        'api.customer.auth.logout-all' => ['POST', route('api.customer.auth.logout-all'), []],

        // The account screen.
        'api.customer.me' => ['GET', route('api.customer.me'), []],
        'api.customer.me.update' => ['PUT', route('api.customer.me.update'), ['first_name' => 'Priyanka']],
        'api.customer.me.password' => ['PUT', route('api.customer.me.password'), [
            'current_password' => 'shopper-password',
            'password' => 'another-good-one', 'password_confirmation' => 'another-good-one',
        ]],

        // Browsing.
        'api.customer.home' => ['GET', route('api.customer.home'), []],
        'api.customer.categories' => ['GET', route('api.customer.categories'), []],
        'api.customer.reference' => ['GET', route('api.customer.reference'), []],
        'api.customer.products' => ['GET', route('api.customer.products', ['sort' => 'newest']), []],
        'api.customer.products.suggestions' => ['GET', route('api.customer.products.suggestions', ['q' => 'wool']), []],
        'api.customer.products.show' => ['GET', route('api.customer.products.show', $f['product']->id), []],
        'api.customer.sellers.show' => ['GET', route('api.customer.sellers.show', $f['vendor']->id), []],

        // The basket.
        'api.customer.cart' => ['GET', route('api.customer.cart'), []],
        'api.customer.cart.items.store' => ['POST', route('api.customer.cart.items.store'), [
            'product_id' => $f['second']->id, 'quantity' => 1,
        ]],
        'api.customer.cart.items.update' => ['PATCH', route('api.customer.cart.items.update', $f['line']->id), ['quantity' => 3]],
        'api.customer.cart.items.save' => ['POST', route('api.customer.cart.items.save', $f['line']->id), []],
        'api.customer.cart.items.move' => ['POST', route('api.customer.cart.items.move', $f['saved_line']->id), []],
        'api.customer.cart.items.destroy' => ['DELETE', route('api.customer.cart.items.destroy', $f['line']->id), []],
        'api.customer.cart.clear' => ['DELETE', route('api.customer.cart.clear'), []],
        'api.customer.cart.coupons' => ['GET', route('api.customer.cart.coupons'), []],
        'api.customer.cart.coupon.apply' => ['POST', route('api.customer.cart.coupon.apply'), ['code' => $f['coupon']->code]],
        'api.customer.cart.coupon.remove' => ['DELETE', route('api.customer.cart.coupon.remove'), []],

        // Saved for later, the other kind.
        'api.customer.wishlist.index' => ['GET', route('api.customer.wishlist.index'), []],
        'api.customer.wishlist.store' => ['POST', route('api.customer.wishlist.store'), ['product_id' => $f['product']->id], 201],
        'api.customer.wishlist.destroy' => ['DELETE', route('api.customer.wishlist.destroy', $f['second']->id), []],

        // Where it is going.
        'api.customer.addresses.index' => ['GET', route('api.customer.addresses.index'), []],
        'api.customer.addresses.store' => ['POST', route('api.customer.addresses.store'), [
            'label' => 'Work', 'first_name' => 'Priya', 'address_line1' => '9 Mount Road',
            'city' => 'Chennai', 'state' => 'Tamil Nadu', 'postcode' => '600002', 'phone' => '9876543210',
        ], 201],
        'api.customer.addresses.update' => ['PUT', route('api.customer.addresses.update', $f['address']->id), [
            'first_name' => 'Priya', 'address_line1' => '44 Beach Road', 'city' => 'Chennai',
        ]],
        'api.customer.addresses.default' => ['PATCH', route('api.customer.addresses.default', $f['address']->id), []],
        'api.customer.addresses.destroy' => ['DELETE', route('api.customer.addresses.destroy', $f['address']->id), []],

        // Checkout and paying.
        'api.customer.checkout' => ['GET', route('api.customer.checkout'), []],
        'api.customer.orders.store' => ['POST', route('api.customer.orders.store'), [
            'address_id' => $f['address']->id, 'payment_method' => 'cash-on-delivery',
        ], 201],
        'api.customer.payments.intent' => ['POST', route('api.customer.payments.intent'), [
            'order_number' => $f['unpaid']->number,
        ], 201],
        'api.customer.payments.verify' => ['POST', route('api.customer.payments.verify'), [
            'razorpay_order_id' => 'order_SMOKE1',
            'razorpay_payment_id' => 'pay_SMOKE1',
            'razorpay_signature' => hash_hmac('sha256', 'order_SMOKE1|pay_SMOKE1', 'rzp_test_secret'),
        ]],
        'api.customer.payments.webhook.razorpay' => ['POST', route('api.customer.payments.webhook.razorpay'), []],

        // Orders, and everything hanging off one.
        'api.customer.orders.index' => ['GET', route('api.customer.orders.index', ['filter' => 'all']), []],
        'api.customer.orders.show' => ['GET', route('api.customer.orders.show', pathNumber($f['delivered'])), []],
        'api.customer.orders.track' => ['GET', route('api.customer.orders.track', pathNumber($f['delivered'])), []],
        'api.customer.orders.invoice' => ['GET', route('api.customer.orders.invoice', pathNumber($f['delivered'])), []],
        'api.customer.invoices.show' => ['GET', URL::temporarySignedRoute(
            'api.customer.invoices.show', now()->addDay(), ['order' => $f['delivered']->id],
        ), []],
        'api.customer.orders.reorder' => ['POST', route('api.customer.orders.reorder', pathNumber($f['delivered'])), []],
        'api.customer.orders.cancellations' => ['POST', route('api.customer.orders.cancellations', pathNumber($f['open'])), [
            'reason' => 'ordered_by_mistake', 'note' => 'Tapped twice.',
        ], 201],
        'api.customer.orders.returns' => ['POST', route('api.customer.orders.returns', pathNumber($f['delivered'])), [
            'reason' => 'damaged', 'method' => 'original',
            'items' => [['order_item_id' => $f['delivered']->items->first()->id, 'quantity' => 1]],
        ], 201],

        // Returns and cancellations, once asked for.
        'api.customer.requests.index' => ['GET', route('api.customer.requests.index'), []],
        'api.customer.requests.show' => ['GET', route('api.customer.requests.show', $f['refund']->number), []],
        'api.customer.requests.withdraw' => ['POST', route('api.customer.requests.withdraw', $f['cancellation']->number), []],

        // Ratings.
        'api.customer.products.reviews' => ['GET', route('api.customer.products.reviews', $f['product']->id), []],
        'api.customer.reviews.store' => ['POST', route('api.customer.reviews.store', $f['product']->id), [
            'rating' => 5, 'title' => 'Beautiful', 'body' => 'Warmer than it looks.',
        ], 201],
        'api.customer.reviews.mine' => ['GET', route('api.customer.reviews.mine'), []],
        'api.customer.reviews.destroy' => ['DELETE', route('api.customer.reviews.destroy', $f['review']->id), []],

        // The bell, and what rings it.
        'api.customer.notifications.index' => ['GET', route('api.customer.notifications.index'), []],
        'api.customer.notifications.unread' => ['GET', route('api.customer.notifications.unread'), []],
        'api.customer.notifications.read' => ['POST', route('api.customer.notifications.read', $f['notification']->id), []],
        'api.customer.notifications.read-all' => ['POST', route('api.customer.notifications.read-all'), []],
        'api.customer.notifications.destroy' => ['DELETE', route('api.customer.notifications.destroy', $f['notification']->id), []],
        'api.customer.push.device.register' => ['POST', route('api.customer.push.device.register'), [
            'token' => 'fcm-new-token', 'platform' => 'android', 'device_name' => 'Pixel 8',
        ]],
        'api.customer.push.device.forget' => ['DELETE', route('api.customer.push.device.forget'), ['token' => 'fcm-smoke-token']],
    ];
}

test('every shopper endpoint answers with real data behind it', function () {
    $calls = shopperCalls($this->fix);
    $failures = [];
    $statuses = [];

    foreach ($calls as $name => $call) {
        [$method, $url, $payload] = $call;
        // What the app should code against. Anything else is the failure.
        $expected = $call[3] ?? 200;

        // A savepoint per call: a DELETE here cannot decide what the next call
        // sees, so the list can be read in any order and stay true.
        DB::beginTransaction();

        try {
            // The guard caches whoever it resolved first; production never
            // sees that, because production is one request per process.
            app('auth')->forgetGuards();

            $response = smokeCall($this->fix, $name, $method, $url, $payload);
            $statuses[$name] = $response->getStatusCode();

            if ($response->getStatusCode() !== $expected) {
                $failures[$name] = $method.' '.parse_url($url, PHP_URL_PATH)
                    .' → '.$response->getStatusCode().', wanted '.$expected.'. '
                    .mb_substr((string) $response->getContent(), 0, 200);
            }
        } finally {
            DB::rollBack();
        }
    }

    expect($failures)->toBe([])
        ->and($statuses)->toHaveCount(count($calls));
});

/**
 * Make one call. Three of them need a step first — a code that was texted, a
 * token that was emailed, a body the gateway signed — and that step is part of
 * what the endpoint is, so it happens here rather than in the table.
 *
 * @param  array<string, mixed>  $f
 * @param  array<string, mixed>  $payload
 */
function smokeCall(array $f, string $name, string $method, string $url, array $payload): TestResponse
{
    if ($name === 'api.customer.auth.otp.verify') {
        $code = test()->postJson(route('api.customer.auth.otp'), ['phone' => '9876543210'])->json('debug_code');

        return test()->postJson($url, ['phone' => '9876543210', 'code' => $code, 'device_name' => 'Pixel 8']);
    }

    if ($name === 'api.customer.auth.reset-password') {
        $token = Password::broker('customers')->createToken($f['customer']);

        return test()->postJson($url, [
            'token' => $token,
            'email' => $f['customer']->email,
            'password' => 'a-brand-new-one',
            'password_confirmation' => 'a-brand-new-one',
        ]);
    }

    if ($name === 'api.customer.payments.webhook.razorpay') {
        $body = json_encode([
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => [
                'id' => 'pay_SMOKE1', 'order_id' => 'order_SMOKE1', 'method' => 'upi',
            ]]],
        ], JSON_THROW_ON_ERROR);

        return test()->call('POST', $url, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, 'whsec_test'),
        ], content: $body);
    }

    // The invoice is HTML on purpose — it is opened in a browser or handed to
    // a download manager, neither of which wants JSON.
    if ($name === 'api.customer.invoices.show') {
        return test()->get($url);
    }

    return test()->json($method, $url, $payload);
}

test('no shopper endpoint is left without a call', function () {
    $registered = collect(Router::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->uri(), 'api/customer/'))
        ->map(fn ($route) => (string) $route->getName())
        ->unique()
        ->sort()
        ->values();

    $called = collect(array_keys(shopperCalls($this->fix)))->sort()->values();

    expect($registered->diff($called)->all())->toBe([])
        ->and($called->diff($registered)->all())->toBe([]);
});
