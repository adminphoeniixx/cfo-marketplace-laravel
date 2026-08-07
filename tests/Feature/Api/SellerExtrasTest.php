<?php

use App\Models\Product;
use App\Models\PushSubscription;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\OrderPlaced;
use App\Notifications\TeamMemberChanged;
use App\Services\Notifier;

/*
|--------------------------------------------------------------------------
| Notifications
|--------------------------------------------------------------------------
*/

test('a seller reads their own notifications and nobody else\'s', function () {
    [$me, $store] = actingAsSeller();
    $colleague = User::factory()->create(['role' => 'vendor', 'vendor_id' => $store->id]);

    $order = orderForStore($store);
    Notifier::send(new OrderPlaced($order->load('items', 'customer')));

    // Both logins on the store were told, but each reads only their own pile.
    expect($me->unreadNotifications()->count())->toBe(1)
        ->and($colleague->unreadNotifications()->count())->toBe(1);

    $this->getJson(route('api.seller.notifications.index'))
        ->assertOk()
        ->assertJsonPath('unread_count', 1)
        ->assertJsonPath('data.0.kind', 'order-placed')
        ->assertJsonCount(1, 'data');
});

test('notifications can be counted, read and deleted', function () {
    [$me] = actingAsSeller();
    $me->notify(new TeamMemberChanged('Priya', 'added', 'vendor', 'Meera'));
    $me->notify(new TeamMemberChanged('Rahul', 'added', 'vendor', 'Meera'));

    $this->getJson(route('api.seller.notifications.unread'))
        ->assertOk()->assertJsonPath('unread', 2);

    $first = $me->notifications()->first();

    $this->postJson(route('api.seller.notifications.read', $first->id))->assertOk();
    expect($me->unreadNotifications()->count())->toBe(1);

    $this->postJson(route('api.seller.notifications.read-all'))->assertOk()->assertJsonPath('unread', 0);

    $this->deleteJson(route('api.seller.notifications.destroy', $first->id))->assertOk();
    expect($me->notifications()->count())->toBe(1);
});

test('another user\'s notification is a 404', function () {
    actingAsSeller();
    $other = adminUser();
    $other->notify(new TeamMemberChanged('Priya', 'added', 'staff', 'Aisha'));
    $theirs = $other->notifications()->first();

    $this->postJson(route('api.seller.notifications.read', $theirs->id))->assertNotFound();
    $this->deleteJson(route('api.seller.notifications.destroy', $theirs->id))->assertNotFound();

    expect($other->unreadNotifications()->count())->toBe(1);
});

test('a pending store can still read notifications', function () {
    actingAsSeller(['status' => 'pending']);

    $this->getJson(route('api.seller.notifications.index'))->assertOk();
    $this->getJson(route('api.seller.push.settings'))->assertOk();
});

test('a device can subscribe to push and unsubscribe again', function () {
    config()->set('webpush.enabled', true);
    config()->set('webpush.public_key', 'test-public-key');
    [$me] = actingAsSeller();

    $this->getJson(route('api.seller.push.settings'))
        ->assertOk()
        ->assertJsonPath('enabled', true)
        ->assertJsonPath('public_key', 'test-public-key');

    $payload = [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/seller-device',
        'keys' => ['p256dh' => 'pub', 'auth' => 'auth'],
    ];

    $this->postJson(route('api.seller.push.subscribe'), $payload)->assertCreated();
    $this->postJson(route('api.seller.push.subscribe'), $payload)->assertCreated();

    expect(PushSubscription::where('user_id', $me->id)->count())->toBe(1);

    $this->deleteJson(route('api.seller.push.unsubscribe'), ['endpoint' => $payload['endpoint']])
        ->assertOk();

    expect(PushSubscription::where('user_id', $me->id)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Team
|--------------------------------------------------------------------------
*/

test('a store can add a second login that sees the same data', function () {
    [$me, $store] = actingAsSeller();
    Product::factory()->count(2)->create(['vendor_id' => $store->id]);

    $this->postJson(route('api.seller.team.store'), [
        'name' => 'Packing desk',
        'email' => 'packing@meera.test',
        'password' => 'a-good-password',
        'password_confirmation' => 'a-good-password',
    ])->assertCreated()->assertJsonPath('data.name', 'Packing desk');

    $mate = User::where('email', 'packing@meera.test')->firstOrFail();

    expect($mate->vendor_id)->toBe($store->id)
        ->and($mate->role)->toBe('vendor');

    // Same store, so the same products.
    $this->withHeader('Authorization', 'Bearer '.$mate->createToken('their phone')->plainTextToken)
        ->getJson(route('api.seller.products.index'))
        ->assertOk()
        ->assertJsonPath('meta.total', 2);
});

test('the team list shows only this store\'s logins', function () {
    [$me, $store] = actingAsSeller();
    User::factory()->create(['role' => 'vendor', 'vendor_id' => $store->id, 'name' => 'Colleague']);

    $elsewhere = Vendor::factory()->create(['status' => 'approved']);
    User::factory()->create(['role' => 'vendor', 'vendor_id' => $elsewhere->id, 'name' => 'Stranger']);

    $response = $this->getJson(route('api.seller.team.index'))->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and(collect($response->json('data'))->pluck('name'))->not->toContain('Stranger')
        ->and(collect($response->json('data'))->firstWhere('id', $me->id)['is_you'])->toBeTrue();
});

test('a seller cannot touch a login on another store', function () {
    actingAsSeller();
    $elsewhere = Vendor::factory()->create(['status' => 'approved']);
    $stranger = User::factory()->create(['role' => 'vendor', 'vendor_id' => $elsewhere->id]);

    $this->putJson(route('api.seller.team.update', $stranger->id), [
        'name' => 'Hijacked', 'email' => $stranger->email,
    ])->assertNotFound();

    $this->patchJson(route('api.seller.team.toggle', $stranger->id))->assertNotFound();
    $this->deleteJson(route('api.seller.team.destroy', $stranger->id))->assertNotFound();

    expect($stranger->fresh()->name)->not->toBe('Hijacked');
});

test('a seller cannot remove themselves or the store\'s last login', function () {
    [$me, $store] = actingAsSeller();

    $this->deleteJson(route('api.seller.team.destroy', $me->id))->assertStatus(422);
    $this->patchJson(route('api.seller.team.toggle', $me->id))->assertStatus(422);

    $mate = User::factory()->create(['role' => 'vendor', 'vendor_id' => $store->id, 'is_active' => false]);

    // The colleague is already inactive, so removing them would leave nobody
    // — except me, who is active, so this is allowed.
    $this->deleteJson(route('api.seller.team.destroy', $mate->id))->assertOk();

    expect(User::whereKey($me->id)->exists())->toBeTrue();
});

test('deactivating a colleague kills their tokens', function () {
    [, $store] = actingAsSeller();
    $mate = User::factory()->create(['role' => 'vendor', 'vendor_id' => $store->id]);
    $mate->createToken('their phone');

    expect($mate->tokens()->count())->toBe(1);

    $this->patchJson(route('api.seller.team.toggle', $mate->id))
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    expect($mate->fresh()->tokens()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Shipping
|--------------------------------------------------------------------------
*/

test('a seller manages their own rates and only sees marketplace ones', function () {
    [, $store] = actingAsSeller();
    $elsewhere = Vendor::factory()->create(['status' => 'approved']);
    $zone = ShippingZone::factory()->create(['is_active' => true]);

    $marketplace = ShippingRate::factory()->create(['shipping_zone_id' => $zone->id, 'vendor_id' => null]);
    $theirs = ShippingRate::factory()->create(['shipping_zone_id' => $zone->id, 'vendor_id' => $elsewhere->id]);

    $created = $this->postJson(route('api.seller.shipping.rates.store'), [
        'shipping_zone_id' => $zone->id,
        'name' => 'Standard within Tamil Nadu',
        'type' => 'flat',
        'rate' => 60,
        'delivery_days_min' => 2,
        'delivery_days_max' => 5,
        'is_active' => true,
        'position' => 1,
    ])->assertCreated();

    $mine = $created->json('data.id');
    expect($created->json('data.editable'))->toBeTrue();

    $list = $this->getJson(route('api.seller.shipping.rates.index'))->assertOk();
    $ids = collect($list->json('data'))->pluck('id');

    expect($ids)->toContain($mine)
        ->and($ids)->toContain($marketplace->id)
        // Another seller's rate is nobody's business.
        ->and($ids)->not->toContain($theirs->id);

    expect(collect($list->json('data'))->firstWhere('id', $marketplace->id)['editable'])->toBeFalse();

    // …and a marketplace fallback cannot be edited away.
    $this->deleteJson(route('api.seller.shipping.rates.destroy', $marketplace->id))->assertNotFound();
    $this->deleteJson(route('api.seller.shipping.rates.destroy', $theirs->id))->assertNotFound();

    $this->deleteJson(route('api.seller.shipping.rates.destroy', $mine))->assertOk();
});

test('shipping zones are readable', function () {
    actingAsSeller();
    ShippingZone::factory()->create(['is_active' => true]);

    $this->getJson(route('api.seller.shipping.zones'))->assertOk()->assertJsonStructure(['data']);
});

/*
|--------------------------------------------------------------------------
| Bulk + analytics
|--------------------------------------------------------------------------
*/

test('bulk actions apply to my products and skip everyone else\'s', function () {
    [, $store] = actingAsSeller();
    $elsewhere = Vendor::factory()->create(['status' => 'approved']);

    $mine = Product::factory()->count(2)->create(['vendor_id' => $store->id, 'status' => 'draft']);
    $theirs = Product::factory()->create(['vendor_id' => $elsewhere->id, 'status' => 'draft']);

    $this->postJson(route('api.seller.products.bulk'), [
        'action' => 'activate',
        'ids' => [...$mine->pluck('id')->all(), $theirs->id],
    ])->assertOk()
        ->assertJsonPath('changed', 2)
        ->assertJsonPath('skipped', 1);

    expect($mine->every(fn ($p) => $p->fresh()->status === 'active'))->toBeTrue()
        // Untouched — it was never ours to change.
        ->and($theirs->fresh()->status)->toBe('draft');
});

test('a bulk call naming nothing of mine is a 404', function () {
    actingAsSeller();
    $elsewhere = Vendor::factory()->create(['status' => 'approved']);
    $theirs = Product::factory()->create(['vendor_id' => $elsewhere->id]);

    $this->postJson(route('api.seller.products.bulk'), [
        'action' => 'delete', 'ids' => [$theirs->id],
    ])->assertNotFound();

    expect(Product::whereKey($theirs->id)->exists())->toBeTrue();
});

test('the sales series returns one point per day, gaps filled', function () {
    [, $store] = actingAsSeller();
    $elsewhere = Vendor::factory()->create(['status' => 'approved']);

    // The factory scatters placed_at across 30 days; pin both inside the window.
    orderForStore($store)->forceFill(['placed_at' => now()->subDay()])->save();
    orderForStore($elsewhere, ['total' => 50000, 'vendor_earning' => 45000])
        ->forceFill(['placed_at' => now()->subDay()])->save();

    $response = $this->getJson(route('api.seller.analytics.sales', ['days' => 7]))->assertOk();

    expect($response->json('series'))->toHaveCount(7)
        ->and($response->json('days'))->toBe(7)
        // Only this store's money.
        ->and((float) $response->json('totals.sales'))->toBe(2000.0);

    $days = collect($response->json('series'))->pluck('date');
    expect($days->unique()->count())->toBe(7);
});

test('the sales window is clamped', function () {
    actingAsSeller();

    $this->getJson(route('api.seller.analytics.sales', ['days' => 9999]))
        ->assertOk()->assertJsonPath('days', 365);

    $this->getJson(route('api.seller.analytics.sales', ['days' => 0]))
        ->assertOk()->assertJsonPath('days', 1);
});

/*
|--------------------------------------------------------------------------
| "Still mine to pack" on a shared basket
|--------------------------------------------------------------------------
|
| The order's own fulfillment_status stays `partially_fulfilled` until every
| seller on the basket has shipped. Counting from that told a seller who had
| finished that they still had an order to pack.
|
*/

test('a seller who has packed everything of theirs is not nagged about it', function () {
    [, $store] = actingAsSeller();
    $elsewhere = Vendor::factory()->create(['status' => 'approved']);

    $order = orderForStore($store);
    $mine = $order->items()->where('vendor_id', $store->id)->firstOrFail();
    $order->items()->create([
        'vendor_id' => $elsewhere->id,
        'name' => 'Their item',
        'unit_price' => 500,
        'quantity' => 1,
        'total' => 500,
    ]);

    // Before packing: one order waiting on us.
    $this->getJson(route('api.seller.dashboard'))
        ->assertOk()->assertJsonPath('needs_attention.unfulfilled_orders', 1);
    $this->getJson(route('api.seller.orders.summary'))
        ->assertOk()->assertJsonPath('unfulfilled', 1);

    $this->postJson(route('api.seller.orders.fulfill', $order->id), [
        'items' => [['id' => $mine->id, 'quantity' => $mine->quantity]],
    ])->assertOk();

    // The order is still partially fulfilled — the other seller has not
    // shipped — but nothing here is ours any more.
    expect($order->fresh()->fulfillment_status)->toBe('partially_fulfilled');

    $this->getJson(route('api.seller.dashboard'))
        ->assertOk()->assertJsonPath('needs_attention.unfulfilled_orders', 0);
    $this->getJson(route('api.seller.orders.summary'))
        ->assertOk()->assertJsonPath('unfulfilled', 0);
});

test('the needs_packing filter returns exactly what the chip counts', function () {
    [, $store] = actingAsSeller();

    $done = orderForStore($store);
    $todo = orderForStore($store);

    $item = $done->items()->where('vendor_id', $store->id)->firstOrFail();
    $this->postJson(route('api.seller.orders.fulfill', $done->id), [
        'items' => [['id' => $item->id, 'quantity' => $item->quantity]],
    ])->assertOk();

    $count = $this->getJson(route('api.seller.orders.summary'))->json('unfulfilled');
    $list = $this->getJson(route('api.seller.orders.index', ['needs_packing' => 1]))->assertOk();

    expect($count)->toBe(1)
        ->and($list->json('meta.total'))->toBe(1)
        ->and($list->json('data.0.id'))->toBe($todo->id);
});

test('a cancelled order is never something to pack', function () {
    [, $store] = actingAsSeller();

    $order = orderForStore($store);
    $order->forceFill(['status' => 'cancelled'])->save();

    $this->getJson(route('api.seller.orders.summary'))
        ->assertOk()->assertJsonPath('unfulfilled', 0);
});
