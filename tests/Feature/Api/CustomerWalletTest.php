<?php

use App\Models\CustomerPaymentMethod;
use App\Models\Order;
use App\Models\Refund;
use App\Models\WalletTransaction;

/*
| The payments screen: saved ways to pay, and store credit.
*/

beforeEach(function () {
    $this->customer = actingAsCustomer();
});

test('the first saved method becomes the default without being asked', function () {
    $this->postJson(route('api.customer.payment-methods.store'), [
        'type' => 'upi',
        'label' => 'Google Pay',
        'masked_value' => 'priya@okhdfc',
        'provider' => 'gpay',
    ])->assertCreated()
        ->assertJsonPath('data.type', 'upi')
        ->assertJsonPath('data.is_default', true)
        // Nothing signed for it, so nothing claims it is verified.
        ->assertJsonPath('data.verified', false);

    $this->postJson(route('api.customer.payment-methods.store'), [
        'type' => 'card',
        'label' => 'HDFC Debit',
        'masked_value' => '•••• 4242',
        'gateway_token' => 'token_from_the_gateway',
        'expires_at' => now()->addYear()->toDateString(),
    ])->assertCreated()
        // The second is not promoted over a choice already made.
        ->assertJsonPath('data.is_default', false)
        // The gateway handed back a token, so this one is real.
        ->assertJsonPath('data.verified', true);

    $this->getJson(route('api.customer.payment-methods.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        // The default is drawn first.
        ->assertJsonPath('data.0.is_default', true);
});

test('a card number is refused however the field is labelled', function () {
    $this->postJson(route('api.customer.payment-methods.store'), [
        'type' => 'card',
        'masked_value' => '4111111111111111',
    ])->assertStatus(422)->assertJsonValidationErrors('masked_value');

    expect(CustomerPaymentMethod::count())->toBe(0);
});

test('the default moves when the default is deleted', function () {
    $first = CustomerPaymentMethod::factory()->create([
        'customer_id' => $this->customer->id, 'is_default' => true,
    ]);
    $second = CustomerPaymentMethod::factory()->upi()->create(['customer_id' => $this->customer->id]);

    $this->deleteJson(route('api.customer.payment-methods.destroy', $first->id))
        ->assertOk()
        ->assertJsonPath('deleted', true);

    // Something has to be the default, or checkout opens on nothing.
    expect($second->fresh()->is_default)->toBeTrue();
});

test('choosing a default unchooses the last one', function () {
    $first = CustomerPaymentMethod::factory()->create([
        'customer_id' => $this->customer->id, 'is_default' => true,
    ]);
    $second = CustomerPaymentMethod::factory()->upi()->create(['customer_id' => $this->customer->id]);

    $this->patchJson(route('api.customer.payment-methods.default', $second->id))
        ->assertOk()
        ->assertJsonPath('data.is_default', true);

    expect($first->fresh()->is_default)->toBeFalse();
});

test('another shopper\'s saved card is not reachable', function () {
    $theirs = CustomerPaymentMethod::factory()->create();

    $this->deleteJson(route('api.customer.payment-methods.destroy', $theirs->id))->assertNotFound();
    $this->patchJson(route('api.customer.payment-methods.default', $theirs->id))->assertNotFound();
});

test('the wallet is a ledger, and lapsed credit does not count', function () {
    WalletTransaction::factory()->create([
        'customer_id' => $this->customer->id, 'amount' => 500, 'description' => 'Refund for #1001',
    ]);
    WalletTransaction::factory()->create([
        'customer_id' => $this->customer->id, 'amount' => -200, 'kind' => 'spend', 'description' => 'Used on #1009',
    ]);
    WalletTransaction::factory()->create([
        'customer_id' => $this->customer->id, 'amount' => 1000, 'expires_at' => now()->subDay(),
    ]);

    $this->getJson(route('api.customer.wallet'))
        ->assertOk()
        ->assertJsonPath('data.balance', 300)
        ->assertJsonPath('data.currency', 'INR')
        ->assertJsonPath('data.source', 'Refund for #1001')
        ->assertJsonCount(3, 'data.transactions');
});

test('a refund to store credit lands in the wallet', function () {
    $order = Order::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => 'completed',
        'grand_total' => 3499,
    ]);
    $item = $order->items()->create([
        'name' => 'Kanchipuram silk saree', 'unit_price' => 3499, 'quantity' => 1, 'total' => 3499,
    ]);

    $refund = Refund::create([
        'number' => Refund::nextNumber(),
        'order_id' => $order->id,
        'customer_id' => $this->customer->id,
        'reason' => 'damaged',
        'method' => 'store_credit',
        'restock' => false,
        'shipping_amount' => 0,
        'adjustment_amount' => 0,
        'total_amount' => 3499,
        'status' => 'approved',
    ]);
    $refund->items()->create(['order_item_id' => $item->id, 'quantity' => 1, 'amount' => 3499]);

    actingAsAdmin();
    $this->patch(route('admin.refunds.process', $refund->id))->assertRedirect();

    // The panel said "refunded"; the wallet used to say zero.
    expect(WalletTransaction::balanceFor($this->customer->id))->toBe(3499.0);
});
