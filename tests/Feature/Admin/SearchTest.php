<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Vendor;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot use admin search', function () {
    $this->get(route('admin.search'))->assertRedirect(route('login'));
});

test('search without a term returns no results', function () {
    actingAsAdmin();

    $this->get(route('admin.search'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Search')
            ->where('term', '')
            ->where('results', null)
        );
});

test('search matches orders products customers and vendors', function () {
    actingAsAdmin();

    Order::factory()->create(['number' => '#7788']);
    Product::factory()->create(['name' => 'Nova Running Shoes']);
    Customer::factory()->create(['first_name' => 'Nova', 'last_name' => 'Sharma']);
    Vendor::factory()->create(['name' => 'Nova Traders']);

    $this->get(route('admin.search', ['q' => 'nova']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('term', 'nova')
            ->has('results.orders', 0)
            ->has('results.products', 1)
            ->has('results.customers', 1)
            ->has('results.vendors', 1)
        );

    $this->get(route('admin.search', ['q' => '7788']))
        ->assertInertia(fn (Assert $page) => $page->has('results.orders', 1));
});

test('search ignores surrounding whitespace', function () {
    actingAsAdmin();

    Product::factory()->create(['name' => 'Linen Shirt']);

    $this->get(route('admin.search', ['q' => '  linen  ']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('term', 'linen')
            ->has('results.products', 1)
        );
});
