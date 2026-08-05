<?php

use App\Models\Cancellation;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Vendor;
use App\Models\VendorPayout;

/*
|--------------------------------------------------------------------------
| The whole point of the seller API
|--------------------------------------------------------------------------
|
| One seller must never see, edit or even confirm the existence of another
| seller's rows. Every test here sets up two stores and checks the second one
| is invisible from the first — a 404, not a 403, so the API does not confirm
| that the id exists at all.
|
*/

test('a seller only lists their own products', function () {
    [, $mine] = actingAsSeller();
    $theirs = Vendor::factory()->create(['status' => 'approved']);

    Product::factory()->count(2)->create(['vendor_id' => $mine->id]);
    Product::factory()->count(3)->create(['vendor_id' => $theirs->id]);

    $response = $this->getJson(route('api.seller.products.index'))->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('meta.total'))->toBe(2);
});

test('another store\'s product is a 404, not a peek', function () {
    actingAsSeller();
    $theirs = Vendor::factory()->create(['status' => 'approved']);
    $product = Product::factory()->create(['vendor_id' => $theirs->id, 'name' => 'Their secret']);

    $this->getJson(route('api.seller.products.show', $product->id))->assertNotFound();
    $this->deleteJson(route('api.seller.products.destroy', $product->id))->assertNotFound();
    $this->patchJson(route('api.seller.products.status', $product->id), ['status' => 'draft'])
        ->assertNotFound();
    $this->patchJson(route('api.seller.products.stock', $product->id), ['stock_quantity' => 0])
        ->assertNotFound();

    expect($product->fresh()->name)->toBe('Their secret')
        ->and($product->fresh()->status)->not->toBe('draft');
});

test('a product is always filed under the token\'s own store', function () {
    [, $mine] = actingAsSeller();
    $theirs = Vendor::factory()->create(['status' => 'approved']);

    // The payload tries to hand the product to another store.
    $response = $this->postJson(route('api.seller.products.store'), [
        'name' => 'Hand-loom saree',
        'type' => 'simple',
        'price' => 2499,
        'status' => 'active',
        'stock_quantity' => 10,
        'vendor_id' => $theirs->id,
    ])->assertCreated();

    $product = Product::findOrFail($response->json('data.id'));

    expect($product->vendor_id)->toBe($mine->id);
});

test('a seller only sees orders holding their own items', function () {
    [, $mine] = actingAsSeller();
    $theirs = Vendor::factory()->create(['status' => 'approved']);

    orderForStore($mine);
    orderForStore($mine);
    $notMine = orderForStore($theirs);

    $response = $this->getJson(route('api.seller.orders.index'))->assertOk();

    expect($response->json('meta.total'))->toBe(2);

    $this->getJson(route('api.seller.orders.show', $notMine->id))->assertNotFound();
});

test('a shared order shows only this store\'s lines and money', function () {
    [, $mine] = actingAsSeller();
    $theirs = Vendor::factory()->create(['status' => 'approved']);

    $order = orderForStore($mine, ['name' => 'My item', 'total' => 2000, 'vendor_earning' => 1800]);
    $order->items()->create([
        'vendor_id' => $theirs->id,
        'name' => 'Their item',
        'unit_price' => 5000,
        'quantity' => 1,
        'total' => 5000,
        'commission_amount' => 500,
        'vendor_earning' => 4500,
    ]);

    $response = $this->getJson(route('api.seller.orders.show', $order->id))->assertOk();

    expect($response->json('data.items'))->toHaveCount(1)
        ->and($response->json('data.items.0.name'))->toBe('My item')
        // Totals are this store's share, not the buyer's basket. JSON does not
        // distinguish 2000 from 2000.0, so compare numerically.
        ->and((float) $response->json('data.totals.items'))->toBe(2000.0)
        ->and((float) $response->json('data.totals.earning'))->toBe(1800.0);

    expect(json_encode($response->json()))->not->toContain('Their item');
});

test('a seller cannot fulfil another store\'s line on a shared order', function () {
    [, $mine] = actingAsSeller();
    $theirs = Vendor::factory()->create(['status' => 'approved']);

    $order = orderForStore($mine);
    $theirItem = $order->items()->create([
        'vendor_id' => $theirs->id,
        'name' => 'Their item',
        'unit_price' => 5000,
        'quantity' => 4,
        'total' => 5000,
    ]);

    $this->postJson(route('api.seller.orders.fulfill', $order->id), [
        'items' => [['id' => $theirItem->id, 'quantity' => 4]],
    ])->assertNotFound();

    expect($theirItem->fresh()->quantity_fulfilled)->toBe(0);
});

test('fulfilling my own lines works and leaves the order partially fulfilled', function () {
    [, $mine] = actingAsSeller();
    $theirs = Vendor::factory()->create(['status' => 'approved']);

    $order = orderForStore($mine);
    $myItem = $order->items()->where('vendor_id', $mine->id)->firstOrFail();
    $order->items()->create([
        'vendor_id' => $theirs->id,
        'name' => 'Their item',
        'unit_price' => 100,
        'quantity' => 1,
        'total' => 100,
    ]);

    $this->postJson(route('api.seller.orders.fulfill', $order->id), [
        'items' => [['id' => $myItem->id, 'quantity' => 2]],
        'tracking_number' => 'TRK123',
        'carrier' => 'Delhivery',
    ])->assertOk();

    expect($myItem->fresh()->fulfillment_status)->toBe('fulfilled')
        // The other vendor has not shipped, so the order as a whole has not.
        ->and($order->fresh()->fulfillment_status)->toBe('partially_fulfilled')
        ->and($order->fresh()->tracking_number)->toBe('TRK123');
});

test('a seller only sees their own payouts', function () {
    [, $mine] = actingAsSeller();
    $theirs = Vendor::factory()->create(['status' => 'approved']);

    VendorPayout::factory()->count(2)->create(['vendor_id' => $mine->id]);
    $notMine = VendorPayout::factory()->create(['vendor_id' => $theirs->id]);

    $this->getJson(route('api.seller.payouts.index'))
        ->assertOk()
        ->assertJsonPath('meta.total', 2);

    $this->getJson(route('api.seller.payouts.show', $notMine->id))->assertNotFound();
});

test('a seller only sees cancellations touching their own items', function () {
    [, $mine] = actingAsSeller();
    $theirs = Vendor::factory()->create(['status' => 'approved']);

    $myOrder = orderForStore($mine);
    $mineCancellation = Cancellation::factory()->create(['order_id' => $myOrder->id]);
    $mineCancellation->items()->create([
        'order_item_id' => $myOrder->items->first()->id,
        'quantity' => 1,
        'amount' => 1000,
    ]);

    $theirOrder = orderForStore($theirs);
    $theirCancellation = Cancellation::factory()->create(['order_id' => $theirOrder->id]);
    $theirCancellation->items()->create([
        'order_item_id' => $theirOrder->items->first()->id,
        'quantity' => 1,
        'amount' => 1000,
    ]);

    $this->getJson(route('api.seller.cancellations.index'))
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $mineCancellation->id);

    $this->getJson(route('api.seller.cancellations.show', $theirCancellation->id))
        ->assertNotFound();
});

test('a seller only sees refunds touching their own items', function () {
    [, $mine] = actingAsSeller();
    $theirs = Vendor::factory()->create(['status' => 'approved']);

    $myOrder = orderForStore($mine);
    $mineRefund = Refund::factory()->create(['order_id' => $myOrder->id]);
    $mineRefund->items()->create([
        'order_item_id' => $myOrder->items->first()->id,
        'quantity' => 1,
        'amount' => 1000,
    ]);

    $theirOrder = orderForStore($theirs);
    $theirRefund = Refund::factory()->create(['order_id' => $theirOrder->id]);
    $theirRefund->items()->create([
        'order_item_id' => $theirOrder->items->first()->id,
        'quantity' => 1,
        'amount' => 1000,
    ]);

    $this->getJson(route('api.seller.refunds.index'))
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    $this->getJson(route('api.seller.refunds.show', $theirRefund->id))->assertNotFound();
    $this->postJson(route('api.seller.refunds.respond', $theirRefund->id), ['note' => 'hi'])
        ->assertNotFound();
});

test('earnings and the dashboard count only this store', function () {
    [, $mine] = actingAsSeller();
    $theirs = Vendor::factory()->create(['status' => 'approved']);

    orderForStore($mine);
    orderForStore($theirs, ['total' => 99999, 'vendor_earning' => 99999]);

    $earnings = $this->getJson(route('api.seller.payouts.earnings'))->assertOk();

    expect((float) $earnings->json('lifetime.gross_sales'))->toBe(2000.0)
        ->and((float) $earnings->json('lifetime.earning'))->toBe(1800.0)
        ->and($earnings->json('lifetime.orders'))->toBe(1);

    $dashboard = $this->getJson(route('api.seller.dashboard'))->assertOk();

    expect((float) $dashboard->json('totals.sales'))->toBe(2000.0)
        ->and($dashboard->json('totals.orders'))->toBe(1);
});

test('uploads land in this store\'s own folder', function () {
    [, $mine] = actingAsSeller();

    // No BunnyCDN credentials in tests, so the call fails at the network edge
    // — but validation and the folder rule are what matter here.
    $this->postJson(route('api.seller.uploads.store'), ['folder' => 'categories'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file', 'folder']);
});
