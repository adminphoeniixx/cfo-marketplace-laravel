<?php

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\Http;

/*
| Taking money.
|
| Every test here runs with credentials configured, because that is the whole
| point: with none, the marketplace keeps its old "assume it is paid" behaviour
| and there is nothing to verify. `CustomerCheckoutTest` covers that side.
*/

beforeEach(function () {
    $this->customer = actingAsCustomer();

    $this->address = $this->customer->addresses()->create([
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

    PaymentMethod::query()->whereNotIn('code', ['upi', 'cash-on-delivery'])->update(['is_active' => false]);

    config([
        'services.razorpay.key' => 'rzp_test_key',
        'services.razorpay.secret' => 'rzp_test_secret',
        'services.razorpay.webhook_secret' => 'whsec_test',
    ]);

    Http::fake([
        'api.razorpay.com/v1/orders' => Http::response([
            'id' => 'order_TESTGATEWAY1',
            'amount' => 100000,
            'currency' => 'INR',
            'receipt' => '#1001',
        ]),
        'api.razorpay.com/v1/payments/*' => Http::response([
            'id' => 'pay_TEST1',
            'method' => 'upi',
            'status' => 'captured',
        ]),
    ]);
});

/** Place an order for one ₹1,000 product and hand it back. */
function payableOrder(string $method = 'upi'): Order
{
    test()->postJson(route('api.customer.cart.items.store'), [
        'product_id' => sellableProduct(['price' => 1000])->id,
    ])->assertOk();

    test()->postJson(route('api.customer.orders.store'), [
        'address_id' => test()->address->id,
        'payment_method' => $method,
    ])->assertCreated();

    return Order::latest('id')->firstOrFail();
}

/** The HMAC Razorpay's checkout widget hands back to the app. */
function paymentSignature(string $gatewayOrderId, string $paymentId): string
{
    return hash_hmac('sha256', "{$gatewayOrderId}|{$paymentId}", 'rzp_test_secret');
}

test('with a gateway configured an online order waits for the money', function () {
    $order = payableOrder();

    expect($order->payment_status)->toBe('pending')
        // Nothing is packed against money that has not arrived.
        ->and($order->status)->toBe('pending')
        ->and($order->paid_at)->toBeNull();

    $this->getJson(route('api.customer.orders.show', ltrim($order->number, '#')))
        ->assertJsonPath('data.payment_required', true)
        ->assertJsonPath('data.transaction_id', null);
});

test('cash on delivery owes nothing up front even with a gateway', function () {
    $order = payableOrder('cash-on-delivery');

    expect($order->payment_status)->toBe('pending')
        ->and($order->status)->toBe('processing');

    $this->getJson(route('api.customer.orders.show', ltrim($order->number, '#')))
        ->assertJsonPath('data.payment_required', false);

    $this->postJson(route('api.customer.payments.intent'), ['order_number' => $order->number])
        ->assertStatus(422)
        ->assertJsonValidationErrors('order_number');
});

test('an intent carries what the checkout widget needs', function () {
    $order = payableOrder();

    $this->postJson(route('api.customer.payments.intent'), ['order_number' => $order->number])
        ->assertCreated()
        ->assertJsonPath('data.gateway', 'razorpay')
        ->assertJsonPath('data.key', 'rzp_test_key')
        ->assertJsonPath('data.gateway_order_id', 'order_TESTGATEWAY1')
        ->assertJsonPath('data.amount', 1000)
        // Paise, converted here rather than in a third place.
        ->assertJsonPath('data.amount_in_paise', 100000)
        ->assertJsonPath('data.prefill.contact', '9876543210');
});

test('tapping pay twice reuses the open intent instead of opening a second', function () {
    $order = payableOrder();

    foreach (range(1, 2) as $ignored) {
        $this->postJson(route('api.customer.payments.intent'), ['order_number' => $order->number])
            ->assertCreated();
    }

    expect(Payment::where('order_id', $order->id)->count())->toBe(1);
});

test('a signed payment settles the order', function () {
    $order = payableOrder();
    $this->postJson(route('api.customer.payments.intent'), ['order_number' => $order->number]);

    $this->postJson(route('api.customer.payments.verify'), [
        'razorpay_order_id' => 'order_TESTGATEWAY1',
        'razorpay_payment_id' => 'pay_TEST1',
        'razorpay_signature' => paymentSignature('order_TESTGATEWAY1', 'pay_TEST1'),
    ])->assertOk()
        ->assertJsonPath('data.payment_status', 'paid')
        ->assertJsonPath('data.transaction_id', 'pay_TEST1')
        ->assertJsonPath('data.payment_required', false);

    $order->refresh();

    expect($order->payment_status)->toBe('paid')
        ->and($order->status)->toBe('processing')
        ->and($order->paid_at)->not->toBeNull()
        ->and(Payment::firstOrFail()->status)->toBe('paid');
});

test('a forged signature settles nothing', function () {
    $order = payableOrder();
    $this->postJson(route('api.customer.payments.intent'), ['order_number' => $order->number]);

    $this->postJson(route('api.customer.payments.verify'), [
        'razorpay_order_id' => 'order_TESTGATEWAY1',
        'razorpay_payment_id' => 'pay_TEST1',
        'razorpay_signature' => 'not-the-hmac',
    ])->assertStatus(422)->assertJsonValidationErrors('razorpay_signature');

    expect($order->fresh()->payment_status)->toBe('pending')
        ->and(Payment::firstOrFail()->status)->toBe('failed');
});

test("another shopper's payment cannot be settled", function () {
    $order = payableOrder();
    $this->postJson(route('api.customer.payments.intent'), ['order_number' => $order->number]);

    actingAsCustomer();

    $this->postJson(route('api.customer.payments.verify'), [
        'razorpay_order_id' => 'order_TESTGATEWAY1',
        'razorpay_payment_id' => 'pay_TEST1',
        'razorpay_signature' => paymentSignature('order_TESTGATEWAY1', 'pay_TEST1'),
    ])->assertNotFound();

    expect($order->fresh()->payment_status)->toBe('pending');
});

test('the webhook saves an order the app never came back from', function () {
    $order = payableOrder();
    $this->postJson(route('api.customer.payments.intent'), ['order_number' => $order->number]);

    $body = json_encode([
        'event' => 'payment.captured',
        'payload' => ['payment' => ['entity' => [
            'id' => 'pay_WEBHOOK1',
            'order_id' => 'order_TESTGATEWAY1',
            'method' => 'card',
        ]]],
    ], JSON_THROW_ON_ERROR);

    $this->call(
        'POST',
        route('api.customer.payments.webhook.razorpay'),
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, 'whsec_test'),
        ],
        content: $body,
    )->assertOk();

    expect($order->fresh()->payment_status)->toBe('paid')
        ->and($order->fresh()->transaction_id)->toBe('pay_WEBHOOK1');
});

test('an unsigned webhook is believed by nobody', function () {
    $order = payableOrder();
    $this->postJson(route('api.customer.payments.intent'), ['order_number' => $order->number]);

    $this->postJson(route('api.customer.payments.webhook.razorpay'), [
        'event' => 'payment.captured',
        'payload' => ['payment' => ['entity' => ['id' => 'pay_X', 'order_id' => 'order_TESTGATEWAY1']]],
    ])->assertStatus(401);

    expect($order->fresh()->payment_status)->toBe('pending');
});

test('a replayed webhook does not capture twice', function () {
    $order = payableOrder();
    $this->postJson(route('api.customer.payments.intent'), ['order_number' => $order->number]);

    $body = json_encode([
        'event' => 'payment.captured',
        'payload' => ['payment' => ['entity' => ['id' => 'pay_ONCE', 'order_id' => 'order_TESTGATEWAY1']]],
    ], JSON_THROW_ON_ERROR);

    $signature = hash_hmac('sha256', $body, 'whsec_test');

    foreach (range(1, 3) as $ignored) {
        $this->call(
            'POST',
            route('api.customer.payments.webhook.razorpay'),
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_RAZORPAY_SIGNATURE' => $signature],
            content: $body,
        )->assertOk();
    }

    expect($order->events()->where('type', 'payment')->count())->toBe(1);
});

test('an order already paid cannot be paid again', function () {
    $order = payableOrder();
    $order->update(['payment_status' => 'paid', 'paid_at' => now()]);

    $this->postJson(route('api.customer.payments.intent'), ['order_number' => $order->number])
        ->assertStatus(422)
        ->assertJsonValidationErrors('order_number');
});

test('with no credentials the gateway is refused rather than half-opened', function () {
    $order = payableOrder();

    config(['services.razorpay.key' => null, 'services.razorpay.secret' => null]);

    $this->postJson(route('api.customer.payments.intent'), ['order_number' => $order->number])
        ->assertStatus(422)
        ->assertJsonValidationErrors('order_number');
});
