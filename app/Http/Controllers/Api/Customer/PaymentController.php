<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Customer\OrderResource;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\Razorpay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Taking money.
 *
 * Three endpoints and one rule: an order becomes `paid` only when Razorpay
 * says so, over a signature this server checks. `create-intent` opens the
 * attempt, `verify` settles it from the app's own hands, and the webhook
 * settles it from Razorpay's — the two race each other on a healthy checkout
 * and `Payment::markPaid()` is idempotent precisely because they do.
 *
 * Nothing here trusts a client-sent amount, a client-sent status, or a
 * client-sent order id: the figure comes off the order, and the signature
 * decides whether the rest is true.
 */
class PaymentController extends Controller
{
    use ScopesToCustomer;

    /**
     * Open a payment for an order that is waiting for one.
     *
     * Repeating the call on the same unpaid order returns the attempt already
     * open rather than a second one — a shopper who backs out of the gateway
     * sheet and taps Pay again is the ordinary case, not a new order.
     */
    public function createIntent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_number' => ['required', 'string', 'max:20'],
        ]);

        $order = $this->findOwnedOrder($request, $data['order_number']);

        $this->assertPayable($order);

        $payment = $order->payments()
            ->where('status', 'created')
            ->where('amount', $order->grand_total)
            ->latest('id')
            ->first();

        if (! $payment) {
            $payment = $this->open($order);
        }

        $customer = $this->customer($request);

        return response()->json([
            'data' => [
                'gateway' => $payment->gateway,
                // The publishable key. The secret never leaves this server.
                'key' => Razorpay::key(),
                'gateway_order_id' => $payment->gateway_order_id,
                'order_number' => $order->number,
                'amount' => (float) $payment->amount,
                // What the checkout widget actually wants, so the app is not
                // the third place in this system doing the ×100.
                'amount_in_paise' => Razorpay::toPaise((float) $payment->amount),
                'currency' => $payment->currency,
                'name' => Setting::cached('store_name', config('app.name')),
                'description' => "Order {$order->number}",
                'prefill' => [
                    'name' => $customer->name,
                    'email' => $customer->email,
                    'contact' => $order->phone ?? $customer->phone,
                ],
            ],
        ], 201);
    }

    /**
     * Settle a payment the app has just been handed by the gateway.
     *
     * The signature is the whole of the security here: anyone can post an
     * order id and a payment id, and only Razorpay can produce the HMAC over
     * the pair.
     */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'razorpay_order_id' => ['required', 'string', 'max:80'],
            'razorpay_payment_id' => ['required', 'string', 'max:80'],
            'razorpay_signature' => ['required', 'string', 'max:255'],
        ]);

        // Scoped to this shopper's own orders, so one customer cannot settle
        // — or read the state of — another's payment.
        $payment = Payment::query()
            ->where('gateway_order_id', $data['razorpay_order_id'])
            ->whereHas('order', fn ($query) => $query->where('customer_id', $this->customer($request)->id))
            ->firstOrFail();

        $verified = Razorpay::verifyPaymentSignature(
            $data['razorpay_order_id'],
            $data['razorpay_payment_id'],
            $data['razorpay_signature'],
        );

        if (! $verified) {
            $payment->markFailed('Signature did not match.', ['payment_id' => $data['razorpay_payment_id']]);

            throw ValidationException::withMessages([
                'razorpay_signature' => 'That payment could not be verified.',
            ]);
        }

        $details = Razorpay::fetchPayment($data['razorpay_payment_id']);

        $payment->markPaid(
            $data['razorpay_payment_id'],
            is_string($details['method'] ?? null) ? $details['method'] : null,
            ['verified_by' => 'app'],
        );

        return response()->json([
            'data' => new OrderResource($payment->order->fresh()->load(['items', 'events'])),
        ]);
    }

    /**
     * Razorpay's own account of what happened.
     *
     * Public by necessity — the gateway carries no token — and trusted only
     * because the body is signed. It is also the path that saves an order when
     * the app dies between paying and telling us, which is exactly when a
     * shopper is angriest.
     */
    public function razorpayWebhook(Request $request): JsonResponse
    {
        $signature = (string) $request->header('X-Razorpay-Signature', '');

        if (! Razorpay::verifyWebhookSignature($request->getContent(), $signature)) {
            Log::warning('Razorpay webhook rejected: bad signature.', ['event' => $request->input('event')]);

            return response()->json(['received' => false], 401);
        }

        $event = (string) $request->input('event');
        $entity = (array) $request->input('payload.payment.entity', []);
        $gatewayOrderId = $entity['order_id'] ?? $request->input('payload.order.entity.id');

        $payment = $gatewayOrderId
            ? Payment::where('gateway_order_id', $gatewayOrderId)->first()
            : null;

        if (! $payment) {
            // Answered 200 on purpose: an event for something this marketplace
            // has no record of is not a failure Razorpay should retry for days.
            Log::info('Razorpay webhook for an unknown order.', ['event' => $event, 'order_id' => $gatewayOrderId]);

            return response()->json(['received' => true]);
        }

        match ($event) {
            'payment.captured', 'order.paid' => $payment->markPaid(
                (string) ($entity['id'] ?? $payment->gateway_payment_id ?? 'unknown'),
                is_string($entity['method'] ?? null) ? $entity['method'] : null,
                ['settled_by' => 'webhook', 'event' => $event],
            ),
            'payment.failed' => $payment->markFailed(
                is_string($entity['error_description'] ?? null) ? $entity['error_description'] : 'Payment failed.',
                ['settled_by' => 'webhook', 'event' => $event],
            ),
            default => null,
        };

        return response()->json(['received' => true]);
    }

    /**
     * @throws ValidationException
     */
    protected function assertPayable(Order $order): void
    {
        if (! Razorpay::enabled()) {
            // A configuration fact, not the shopper's mistake — but it has to
            // reach them as something the app can show rather than a 500.
            throw ValidationException::withMessages([
                'order_number' => 'Online payment is not available right now.',
            ]);
        }

        if ($order->payment_status === 'paid') {
            throw ValidationException::withMessages(['order_number' => 'This order is already paid.']);
        }

        if ($order->isPayOnDelivery()) {
            throw ValidationException::withMessages([
                'order_number' => 'This order is being paid on delivery.',
            ]);
        }

        if (in_array($order->status, ['cancelled', 'refunded'], true)) {
            throw ValidationException::withMessages(['order_number' => 'This order has been cancelled.']);
        }
    }

    /**
     * @throws ValidationException
     */
    protected function open(Order $order): Payment
    {
        try {
            $gatewayOrder = Razorpay::createOrder($order, (float) $order->grand_total);
        } catch (RuntimeException $e) {
            Log::error('Razorpay order could not be opened.', [
                'order' => $order->number,
                'error' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'order_number' => 'The payment provider could not be reached. Try again in a moment.',
            ]);
        }

        return $order->payments()->create([
            'customer_id' => $order->customer_id,
            'gateway' => 'razorpay',
            'gateway_order_id' => $gatewayOrder['id'],
            'status' => 'created',
            'amount' => (float) $order->grand_total,
            'currency' => $order->currency ?: 'INR',
            'meta' => ['receipt' => $gatewayOrder['receipt'] ?? null],
        ]);
    }
}
