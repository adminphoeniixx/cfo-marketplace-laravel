<?php

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Refund;
use App\Models\WalletTransaction;

/*
| Store credit that can actually be spent.
|
| `GET /wallet` has been truthful since the day refunds started settling into
| it, and checkout never offered it — so a shopper owed ₹900 by the marketplace
| paid ₹900 of their own money for the next order and watched the balance sit
| there. This closes that.
|
| The rule the whole feature hangs on: money goes back the way it came. Credit
| spent on an order returns to the balance when that order is refunded, and it
| does so whatever method the admin picks for the rest — a card that was never
| charged cannot be refunded to.
*/

beforeEach(function () {
    // Only the two the app actually offers, so a code cannot drift.
    PaymentMethod::query()
        ->whereNotIn('code', ['upi', 'cash-on-delivery'])
        ->update(['is_active' => false]);
});

/** A shopper with an address and, optionally, credit already owed to them. */
function creditedShopper(float $amount): Customer
{
    $customer = actingAsCustomer();

    $customer->addresses()->create([
        'label' => 'Home', 'first_name' => 'Priya', 'last_name' => 'Nair',
        'address_line1' => '42 Beach Road', 'city' => 'Chennai',
        'state' => 'Tamil Nadu', 'postcode' => '600090', 'country' => 'IN',
        'phone' => '9876543210', 'is_default_shipping' => true,
    ]);

    if ($amount > 0) {
        WalletTransaction::create([
            'customer_id' => $customer->id,
            'amount' => $amount,
            'kind' => 'refund',
            'description' => 'Earlier refund',
            'expires_at' => now()->addYear(),
        ]);
    }

    return $customer;
}

/** A basket costing roughly the given amount, from one approved store. */
function basketWorth(float $amount): void
{
    test()->postJson(route('api.customer.cart.items.store'), [
        'product_id' => sellableProduct(['price' => $amount])->id,
        'quantity' => 1,
    ])->assertOk();
}

/** Place the basket against the shopper's default address. */
function placeOrder(array $overrides = []): Order
{
    test()->postJson(route('api.customer.orders.store'), [
        'address_id' => CustomerAddress::query()->value('id'),
        'payment_method' => 'upi',
        ...$overrides,
    ])->assertCreated();

    return Order::latest('id')->firstOrFail();
}

test('the checkout screen offers what the balance would actually cover', function () {
    creditedShopper(5000);
    basketWorth(900);

    $response = $this->getJson('/api/customer/checkout')->assertOk();

    $wallet = $response->json('wallet');
    $total = (float) $response->json('totals.grand_total');

    expect((float) $wallet['balance'])->toBe(5000.0)
        // Not the balance: a ₹5,000 balance against a smaller basket spends
        // only what the basket costs.
        ->and((float) $wallet['applicable'])->toBe(round($total, 2))
        ->and($wallet['covers_order'])->toBeTrue()
        ->and((float) $wallet['remaining_to_pay'])->toBe(0.0);
});

test('a balance smaller than the basket covers part of it', function () {
    creditedShopper(200);
    basketWorth(900);

    $wallet = $this->getJson('/api/customer/checkout')->assertOk()->json('wallet');

    expect((float) $wallet['applicable'])->toBe(200.0)
        ->and($wallet['covers_order'])->toBeFalse()
        ->and($wallet['remaining_to_pay'])->toBeGreaterThan(0);
});

test('credit is not spent unless the shopper asked', function () {
    $customer = creditedShopper(5000);
    basketWorth(900);

    $order = placeOrder(['use_wallet' => false]);

    expect((float) $order->wallet_amount)->toBe(0.0)
        ->and(WalletTransaction::balanceFor($customer->id))->toBe(5000.0);
});

test('credit covering the whole order settles it outright', function () {
    $customer = creditedShopper(5000);
    basketWorth(900);

    $order = placeOrder(['use_wallet' => true]);

    expect((float) $order->wallet_amount)->toBe((float) $order->grand_total)
        ->and($order->payment_status)->toBe('paid')
        ->and($order->paid_at)->not->toBeNull()
        // Nothing left for a gateway to collect.
        ->and($order->status)->toBe('processing');

    // And the ledger is a ledger: the balance is the sum of its movements.
    expect(WalletTransaction::balanceFor($customer->id))
        ->toBe(round(5000 - (float) $order->grand_total, 2));

    $spend = WalletTransaction::where('kind', 'spend')->firstOrFail();
    expect((float) $spend->amount)->toBe(-(float) $order->grand_total)
        ->and($spend->order_id)->toBe($order->id);
});

test('a partial balance leaves the rest to pay', function () {
    $customer = creditedShopper(200);
    basketWorth(900);

    $order = placeOrder(['use_wallet' => true]);

    expect((float) $order->wallet_amount)->toBe(200.0)
        ->and((float) $order->grand_total)->toBeGreaterThan(200.0)
        ->and(WalletTransaction::balanceFor($customer->id))->toBe(0.0);

    // What "still owed" means depends on the gateway, and that is the
    // marketplace's existing behaviour rather than anything credit changes:
    // with Razorpay configured the remainder is collected before packing.
    $order->recordEvent('payment', 'checked', null);
    expect((float) $order->grand_total - (float) $order->wallet_amount)
        ->toBeGreaterThan(0.0);
});

test('a shopper with no credit is unaffected by asking for it', function () {
    creditedShopper(0);
    basketWorth(900);

    $order = placeOrder(['use_wallet' => true]);

    expect((float) $order->wallet_amount)->toBe(0.0);
});

test('lapsed credit cannot be spent', function () {
    $customer = creditedShopper(0);
    WalletTransaction::create([
        'customer_id' => $customer->id, 'amount' => 500, 'kind' => 'refund',
        'description' => 'Expired credit', 'expires_at' => now()->subDay(),
    ]);
    basketWorth(900);

    expect((float) $this->getJson('/api/customer/checkout')->json('wallet.balance'))->toBe(0.0);

    expect((float) placeOrder(['use_wallet' => true])->wallet_amount)->toBe(0.0);
});

test('the order tells the app how much of it credit paid for', function () {
    creditedShopper(200);
    basketWorth(900);
    $order = placeOrder(['use_wallet' => true]);

    $number = ltrim($order->number, '#');

    expect((float) $this->getJson("/api/customer/orders/{$number}")->assertOk()->json('data.totals.wallet_amount'))
        ->toBe(200.0);
});

test('credit spent comes back to credit when the order is refunded', function () {
    $customer = creditedShopper(1000);
    basketWorth(900);
    $order = placeOrder(['use_wallet' => true]);

    $spent = (float) $order->wallet_amount;
    expect(WalletTransaction::balanceFor($customer->id))->toBe(round(1000 - $spent, 2));

    actingAsAdmin();
    $refund = Refund::factory()->create([
        'order_id' => $order->id, 'customer_id' => $customer->id,
        'status' => 'approved', 'total_amount' => $spent,
        // Deliberately *not* store credit: the point is that the wallet share
        // comes back regardless of what the admin picked for the rest.
        'method' => 'original',
    ]);

    $this->patch(route('admin.refunds.process', $refund), ['transaction_reference' => 'TXN-1'])
        ->assertRedirect();

    expect(WalletTransaction::balanceFor($customer->id))->toBe(1000.0);
});

test('two refunds on one order cannot return the credit twice', function () {
    $customer = creditedShopper(1000);
    basketWorth(900);
    $order = placeOrder(['use_wallet' => true]);
    $spent = (float) $order->wallet_amount;

    actingAsAdmin();

    foreach ([1, 2] as $i) {
        $refund = Refund::factory()->create([
            'order_id' => $order->id, 'customer_id' => $customer->id,
            'status' => 'approved', 'total_amount' => $spent, 'method' => 'original',
        ]);
        $this->patch(route('admin.refunds.process', $refund), ['transaction_reference' => "TXN-{$i}"]);
    }

    // Back to where they started, and no further.
    expect(WalletTransaction::balanceFor($customer->id))->toBe(1000.0);
});

test('reordering something whose price moved adds it and says so', function () {
    creditedShopper(0);

    $product = sellableProduct(['price' => 1000]);
    $order = Order::factory()->create(['customer_id' => Customer::first()->id]);
    $order->items()->create([
        'product_id' => $product->id, 'vendor_id' => $product->vendor_id,
        'name' => $product->name, 'sku' => 'SKU-1',
        'unit_price' => 1000, 'quantity' => 1, 'total' => 1000,
    ]);

    // The seller put the price up after the order was placed.
    $product->update(['price' => 1250]);

    $added = $this->postJson('/api/customer/orders/'.ltrim($order->number, '#').'/reorder')
        ->assertOk()->json('added.0');

    // Added, not refused — a price that moved must not block a reorder.
    expect($added['status'])->toBe('added')
        ->and($added['price_changed'])->toBeTrue()
        ->and((float) $added['previous_price'])->toBe(1000.0)
        ->and((float) $added['price'])->toBe(1250.0);
});

test('an unchanged price says so too, rather than saying nothing', function () {
    creditedShopper(0);

    $product = sellableProduct(['price' => 1000]);
    $order = Order::factory()->create(['customer_id' => Customer::first()->id]);
    $order->items()->create([
        'product_id' => $product->id, 'vendor_id' => $product->vendor_id,
        'name' => $product->name, 'sku' => 'SKU-1',
        'unit_price' => 1000, 'quantity' => 1, 'total' => 1000,
    ]);

    $added = $this->postJson('/api/customer/orders/'.ltrim($order->number, '#').'/reorder')
        ->assertOk()->json('added.0');

    expect($added['price_changed'])->toBeFalse();
});
