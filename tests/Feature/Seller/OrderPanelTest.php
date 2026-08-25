<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Orders are the screen the marketplace panel got wrong: `admin.orders.index`
 * read a vendor id as an optional filter rather than a scope, so a signed-in
 * vendor was served the whole book. These tests hold the seller panel to the
 * opposite standard — nothing but this store's own lines, and its own money.
 */
function sellerWithOrders(): array
{
    $store = Vendor::factory()->create(['status' => 'approved', 'commission_rate' => 10]);

    $user = User::factory()->create([
        'role' => 'vendor',
        'vendor_id' => $store->id,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    test()->actingAs($user);

    return [$user, $store];
}

function orderWithLine(Vendor $vendor, array $item = [], array $order = []): Order
{
    $created = Order::factory()->create(['status' => 'processing', ...$order]);

    OrderItem::factory()->forOrder($created)->create([
        'vendor_id' => $vendor->id,
        'product_id' => Product::factory()->create(['vendor_id' => $vendor->id])->id,
        'quantity' => 2,
        'total' => 1000,
        'commission_amount' => 100,
        'vendor_earning' => 900,
        ...$item,
    ]);

    return $created->fresh();
}

test('the order list holds only orders this store has a line on', function () {
    [, $store] = sellerWithOrders();
    $other = Vendor::factory()->create(['status' => 'approved']);

    $mine = orderWithLine($store);
    $theirs = orderWithLine($other);

    $props = $this->get(route('seller.orders.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('seller/orders/Index'))
        ->viewData('page')['props'];

    $ids = collect($props['orders']['data'])->pluck('id');

    expect($ids)->toContain($mine->id)
        ->and($ids)->not->toContain($theirs->id)
        ->and($props['counts']['all'])->toBe(1);
});

test('the money shown is this store\'s share, not the basket total', function () {
    [, $store] = sellerWithOrders();
    $other = Vendor::factory()->create(['status' => 'approved']);

    // One basket, two sellers — the case that makes grand_total the wrong number.
    $shared = orderWithLine($store, ['total' => 1000, 'vendor_earning' => 900]);
    OrderItem::factory()->forOrder($shared)->create([
        'vendor_id' => $other->id,
        'total' => 7777,
        'commission_amount' => 777,
        'vendor_earning' => 7000,
    ]);

    $row = collect(
        $this->get(route('seller.orders.index'))->viewData('page')['props']['orders']['data']
    )->firstWhere('id', $shared->id);

    expect($row['mine']['total'])->toBe(1000.0)
        ->and($row['mine']['earning'])->toBe(900.0);

    $props = $this->get(route('seller.orders.show', $shared))
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['mine']['total'])->toBe(1000.0)
        ->and($props['mine']['earning'])->toBe(900.0)
        ->and($props['sharedBasket'])->toBeTrue()
        // The other seller's line must not be in the payload at all.
        ->and(collect($props['order']['items'])->pluck('vendor_id')->unique()->all())
        ->toBe([$store->id]);
});

test('an order with no line of ours is a 404', function () {
    sellerWithOrders();
    $theirs = orderWithLine(Vendor::factory()->create(['status' => 'approved']));

    $this->get(route('seller.orders.show', $theirs))->assertNotFound();
    $this->post(route('seller.orders.fulfill', $theirs), [
        'items' => [['id' => $theirs->items->first()->id, 'quantity' => 1]],
    ])->assertNotFound();
    $this->post(route('seller.orders.notes', $theirs), ['note' => 'hello'])->assertNotFound();
});

test('a seller can only pack their own lines of a shared basket', function () {
    [, $store] = sellerWithOrders();
    $other = Vendor::factory()->create(['status' => 'approved']);

    $shared = orderWithLine($store, ['quantity' => 2]);
    $foreignLine = OrderItem::factory()->forOrder($shared)->create([
        'vendor_id' => $other->id,
        'quantity' => 3,
    ]);
    $ourLine = $shared->items()->where('vendor_id', $store->id)->first();

    // Passing the other seller's line id 404s rather than shipping their goods.
    $this->post(route('seller.orders.fulfill', $shared), [
        'items' => [['id' => $foreignLine->id, 'quantity' => 3]],
    ])->assertNotFound();

    expect($foreignLine->fresh()->quantity_fulfilled)->toBe(0);

    $this->post(route('seller.orders.fulfill', $shared), [
        'items' => [['id' => $ourLine->id, 'quantity' => 2]],
    ])->assertSessionHas('success');

    // Ours is done; the order is not, because theirs is still outstanding.
    expect($ourLine->fresh()->fulfillment_status)->toBe('fulfilled')
        ->and($shared->fresh()->fulfillment_status)->toBe('partially_fulfilled')
        ->and($shared->fresh()->status)->toBe('processing');
});

test('packing everything on a single-seller order ships it', function () {
    [, $store] = sellerWithOrders();

    $order = orderWithLine($store, ['quantity' => 2]);
    $line = $order->items->first();

    $this->post(route('seller.orders.fulfill', $order), [
        'items' => [['id' => $line->id, 'quantity' => 2]],
        'tracking_number' => 'TRK-77',
        'carrier' => 'Delhivery',
    ])->assertSessionHas('success');

    expect($order->fresh())
        ->fulfillment_status->toBe('fulfilled')
        ->status->toBe('shipped')
        ->tracking_number->toBe('TRK-77');
});

test('a note is recorded against the order timeline', function () {
    [, $store] = sellerWithOrders();
    $order = orderWithLine($store);

    $this->post(route('seller.orders.notes', $order), ['note' => 'Packed, awaiting pickup'])
        ->assertSessionHas('success');

    $this->assertDatabaseHas('order_events', [
        'order_id' => $order->id,
        'type' => 'note',
        'body' => 'Packed, awaiting pickup',
    ]);
});

test('the seller panel offers no way to change status or payment', function () {
    [, $store] = sellerWithOrders();
    $order = orderWithLine($store);

    // Those routes exist only under /admin, which is shut to vendors.
    $this->patch(route('admin.orders.status', $order), ['status' => 'completed'])
        ->assertRedirect('/seller');
    $this->patch(route('admin.orders.payment', $order), ['payment_status' => 'paid'])
        ->assertRedirect('/seller');

    expect($order->fresh()->status)->toBe('processing');
});

test('the sidebar to-pack count follows our own lines, not the order status', function () {
    [, $store] = sellerWithOrders();
    $other = Vendor::factory()->create(['status' => 'approved']);

    // We have packed ours; the other seller has not. The order still reads
    // partially fulfilled, but there is nothing left for us to do.
    $shared = orderWithLine($store, ['quantity' => 2, 'quantity_fulfilled' => 2]);
    OrderItem::factory()->forOrder($shared)->create([
        'vendor_id' => $other->id,
        'quantity' => 3,
        'quantity_fulfilled' => 0,
    ]);
    $shared->update(['fulfillment_status' => 'partially_fulfilled']);

    $props = $this->get(route('seller.orders.index'))->viewData('page')['props'];

    expect($props['summary']['to_pack'])->toBe(0);
});
