<?php

use App\Models\Order;
use App\Models\PaymentMethod;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot manage payment methods', function () {
    $this->get(route('admin.payments.index'))->assertRedirect(route('login'));
});

test('the default methods ship with the schema', function () {
    expect(PaymentMethod::pluck('name')->all())
        ->toContain('UPI', 'Cash on Delivery', 'Net Banking');
});

test('methods are listed with how much they have been used', function () {
    actingAsAdmin();

    PaymentMethod::query()->delete();
    $upi = PaymentMethod::factory()->create(['name' => 'UPI', 'code' => 'upi']);

    Order::factory()->count(2)->create(['payment_method' => 'UPI', 'grand_total' => 500]);
    Order::factory()->cancelled()->create(['payment_method' => 'UPI', 'grand_total' => 900]);
    // A value nobody configured — the screen should call it out.
    Order::factory()->create(['payment_method' => 'Barter']);

    $this->get(route('admin.payments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/payments/Index')
            ->has('methods', 1)
            ->where('methods.0.id', $upi->id)
            ->where('methods.0.orders_count', 2)
            ->where('methods.0.revenue', 1000)
            ->where('unlisted', ['Barter'])
        );
});

test('a method can be added, renamed and toggled', function () {
    actingAsAdmin();

    $this->post(route('admin.payments.store'), [
        'name' => 'Bank Transfer',
        'description' => 'NEFT / RTGS',
        'is_active' => true,
        'position' => 3,
    ])->assertSessionHas('success');

    $method = PaymentMethod::where('name', 'Bank Transfer')->firstOrFail();

    expect($method->code)->toBe('bank-transfer')->and($method->is_active)->toBeTrue();

    // Orders carry the label, so a rename has to carry them along.
    $order = Order::factory()->create(['payment_method' => 'Bank Transfer']);

    $this->put(route('admin.payments.update', $method), [
        'name' => 'Bank transfer (NEFT)',
        'is_active' => true,
        'position' => 3,
    ])->assertSessionHas('success');

    expect($order->fresh()->payment_method)->toBe('Bank transfer (NEFT)');

    $this->patch(route('admin.payments.toggle', $method))->assertSessionHas('success');

    expect($method->fresh()->is_active)->toBeFalse();
});

test('method names cannot collide', function () {
    actingAsAdmin();

    PaymentMethod::query()->delete();
    PaymentMethod::factory()->create(['name' => 'UPI', 'code' => 'upi']);

    $this->post(route('admin.payments.store'), ['name' => 'UPI'])
        ->assertSessionHasErrors('name');
});

test('a method in use cannot be deleted', function () {
    actingAsAdmin();

    PaymentMethod::query()->delete();
    $method = PaymentMethod::factory()->create(['name' => 'Wallet', 'code' => 'wallet']);
    Order::factory()->create(['payment_method' => 'Wallet']);

    $this->delete(route('admin.payments.destroy', $method))->assertSessionHas('error');

    expect($method->fresh())->not->toBeNull();

    Order::query()->delete();

    $this->delete(route('admin.payments.destroy', $method))->assertSessionHas('success');

    expect($method->fresh())->toBeNull();
});

test('only active methods are offered when raising an order', function () {
    actingAsAdmin();

    PaymentMethod::query()->delete();
    PaymentMethod::factory()->create(['name' => 'UPI', 'code' => 'upi', 'position' => 0]);
    PaymentMethod::factory()->inactive()->create(['name' => 'Wallet', 'code' => 'wallet', 'position' => 1]);

    $this->get(route('admin.orders.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('paymentMethods', ['UPI']));
});

test('the payment method can be corrected on an order', function () {
    actingAsAdmin();

    $order = Order::factory()->create(['payment_method' => 'UPI', 'payment_status' => 'pending']);

    $this->patch(route('admin.orders.payment', $order), [
        'payment_status' => 'paid',
        'payment_method' => 'Cash on Delivery',
        'transaction_id' => 'TXN-1',
    ])->assertSessionHas('success');

    expect($order->fresh())
        ->payment_method->toBe('Cash on Delivery')
        ->payment_status->toBe('paid');
});

test('orders can be filtered by payment method', function () {
    actingAsAdmin();

    Order::factory()->create(['payment_method' => 'UPI']);
    Order::factory()->create(['payment_method' => 'Wallet']);

    $this->get(route('admin.orders.index', ['payment_method' => 'UPI']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 1)
            ->where('orders.data.0.payment_method', 'UPI')
        );
});
