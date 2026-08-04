<?php

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot manage customers', function () {
    $this->get(route('admin.customers.index'))->assertRedirect(route('login'));
});

test('customer index lists customers with counts and summary', function () {
    actingAsAdmin();

    Customer::factory()->create(['total_spent' => 1000, 'orders_count' => 2]);
    Customer::factory()->create(['total_spent' => 500, 'orders_count' => 1]);
    Customer::factory()->blocked()->create(['total_spent' => 0]);

    $this->get(route('admin.customers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/customers/Index')
            ->has('customers.data', 3)
            ->where('counts.all', 3)
            ->where('counts.active', 2)
            ->where('counts.blocked', 1)
            ->where('summary.total_spent', 1500)
            ->where('summary.repeat_customers', 1)
        );
});

test('customers can be searched sorted and filtered', function () {
    actingAsAdmin();

    Customer::factory()->create(['first_name' => 'Meera', 'email' => 'meera@example.com', 'total_spent' => 100]);
    Customer::factory()->create(['first_name' => 'Arjun', 'email' => 'arjun@example.com', 'total_spent' => 9000]);
    Customer::factory()->blocked()->create(['first_name' => 'Zoya']);

    $this->get(route('admin.customers.index', ['search' => 'meera']))
        ->assertInertia(fn (Assert $page) => $page->has('customers.data', 1));

    $this->get(route('admin.customers.index', ['status' => 'blocked']))
        ->assertInertia(fn (Assert $page) => $page->has('customers.data', 1));

    $this->get(route('admin.customers.index', ['sort' => 'spend']))
        ->assertInertia(fn (Assert $page) => $page->where('customers.data.0.first_name', 'Arjun'));

    $this->get(route('admin.customers.index', ['sort' => 'name']))
        ->assertInertia(fn (Assert $page) => $page->where('customers.data.0.first_name', 'Arjun'));
});

test('a customer can be created', function () {
    actingAsAdmin();

    $this->post(route('admin.customers.store'), [
        'first_name' => 'Rhea',
        'last_name' => 'Kapoor',
        'email' => 'rhea@example.com',
        'status' => 'active',
        'accepts_marketing' => true,
    ])->assertSessionHas('success');

    $this->assertDatabaseHas('customers', ['email' => 'rhea@example.com', 'first_name' => 'Rhea']);
});

test('customer emails must be unique and valid', function () {
    actingAsAdmin();

    Customer::factory()->create(['email' => 'taken@example.com']);

    $this->post(route('admin.customers.store'), [
        'first_name' => 'Rhea',
        'email' => 'taken@example.com',
        'status' => 'active',
    ])->assertSessionHasErrors('email');

    $this->post(route('admin.customers.store'), [
        'first_name' => 'Rhea',
        'email' => 'not-an-email',
        'status' => 'active',
    ])->assertSessionHasErrors('email');
});

test('the customer show screen reports lifetime stats', function () {
    actingAsAdmin();

    $customer = Customer::factory()->create(['total_spent' => 3000]);
    Order::factory()->create(['customer_id' => $customer->id, 'grand_total' => 2000, 'refunded_total' => 200]);
    Order::factory()->cancelled()->create(['customer_id' => $customer->id, 'grand_total' => 1000]);

    $this->get(route('admin.customers.show', $customer))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/customers/Show')
            ->where('stats.lifetime_value', 3000)
            ->where('stats.orders_count', 2)
            ->where('stats.average_order', 1500)
            ->where('stats.refunded', 200)
            ->where('stats.cancelled', 1)
        );
});

test('a customer can be updated', function () {
    actingAsAdmin();

    $customer = Customer::factory()->create(['first_name' => 'Old']);

    $this->put(route('admin.customers.update', $customer), [
        'first_name' => 'New',
        'last_name' => 'Name',
        'email' => $customer->email,
        'status' => 'active',
    ])
        ->assertRedirect(route('admin.customers.show', $customer))
        ->assertSessionHas('success');

    expect($customer->fresh()->first_name)->toBe('New');
});

test('customer status can be toggled', function () {
    actingAsAdmin();

    $customer = Customer::factory()->create();

    $this->from(route('admin.customers.show', $customer))
        ->patch(route('admin.customers.toggle-status', $customer))
        ->assertSessionHas('success');

    expect($customer->fresh()->status)->toBe('blocked');

    $this->patch(route('admin.customers.toggle-status', $customer));

    expect($customer->fresh()->status)->toBe('active');
});

test('a customer can be soft deleted', function () {
    actingAsAdmin();

    $customer = Customer::factory()->create();

    $this->delete(route('admin.customers.destroy', $customer))
        ->assertRedirect(route('admin.customers.index'));

    $this->assertSoftDeleted('customers', ['id' => $customer->id]);
});

test('an address can be added to a customer', function () {
    actingAsAdmin();

    $customer = Customer::factory()->create();

    $this->from(route('admin.customers.show', $customer))
        ->post(route('admin.customers.addresses.store', $customer), [
            'label' => 'Office',
            'first_name' => 'Rhea',
            'address_line1' => '12 MG Road',
            'city' => 'Pune',
            'country' => 'IN',
            'is_default_shipping' => true,
        ])
        ->assertSessionHas('success');

    $this->assertDatabaseHas('customer_addresses', [
        'customer_id' => $customer->id,
        'label' => 'Office',
        'is_default_shipping' => true,
    ]);
});

test('address validation requires the core fields', function () {
    actingAsAdmin();

    $customer = Customer::factory()->create();

    $this->post(route('admin.customers.addresses.store', $customer), [])
        ->assertSessionHasErrors(['label', 'first_name', 'address_line1', 'city', 'country']);
});

test('only one address stays the default', function () {
    actingAsAdmin();

    $customer = Customer::factory()->create();
    $first = CustomerAddress::factory()->defaultAddress()->create(['customer_id' => $customer->id]);
    $second = CustomerAddress::factory()->create(['customer_id' => $customer->id]);

    $this->put(route('admin.customers.addresses.update', [$customer, $second]), [
        'label' => 'Home',
        'first_name' => 'Rhea',
        'address_line1' => '9 Link Road',
        'city' => 'Mumbai',
        'country' => 'IN',
        'is_default_billing' => true,
        'is_default_shipping' => true,
    ])->assertSessionHas('success');

    expect($first->fresh())
        ->is_default_billing->toBeFalse()
        ->is_default_shipping->toBeFalse()
        ->and($second->fresh()->is_default_shipping)->toBeTrue();
});

test('addresses of another customer cannot be touched', function () {
    actingAsAdmin();

    $customer = Customer::factory()->create();
    $foreign = CustomerAddress::factory()->create();

    $this->delete(route('admin.customers.addresses.destroy', [$customer, $foreign]))
        ->assertNotFound();

    $this->assertDatabaseHas('customer_addresses', ['id' => $foreign->id]);
});

test('an address can be removed', function () {
    actingAsAdmin();

    $customer = Customer::factory()->create();
    $address = CustomerAddress::factory()->create(['customer_id' => $customer->id]);

    $this->from(route('admin.customers.show', $customer))
        ->delete(route('admin.customers.addresses.destroy', [$customer, $address]))
        ->assertSessionHas('success');

    $this->assertDatabaseMissing('customer_addresses', ['id' => $address->id]);
});
