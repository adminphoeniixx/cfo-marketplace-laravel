<?php

use App\Models\Cancellation;
use App\Models\Refund;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayout;
use App\Notifications\CancellationDecided;
use App\Notifications\OrderStatusChanged;
use App\Notifications\PayoutStatusChanged;
use App\Notifications\RefundDecided;
use App\Notifications\StoreStatusChanged;
use App\Notifications\TeamMemberChanged;
use App\Support\Roles;
use Illuminate\Support\Facades\Notification;

/**
 * Every "this needs your attention" was wired; none of the outcomes were.
 * A seller could raise a request, or wait on a payout, and only ever learn
 * what happened by opening the screen and looking.
 */

/** A store with one seller login, and an admin to act as. */
function storeAndAdmin(): array
{
    $vendor = Vendor::factory()->create(['status' => 'pending']);

    $seller = User::factory()->create([
        'role' => 'vendor',
        'vendor_id' => $vendor->id,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    return [$vendor, $seller, adminUser()];
}

/*
|--------------------------------------------------------------------------
| Store status
|--------------------------------------------------------------------------
*/

test('a seller is told when their store is approved', function () {
    Notification::fake();
    [$vendor, $seller, $admin] = storeAndAdmin();

    $this->actingAs($admin)
        ->patch(route('admin.vendors.status', $vendor), ['status' => 'approved'])
        ->assertRedirect();

    Notification::assertSentTo($seller, StoreStatusChanged::class,
        fn (StoreStatusChanged $n) => $n->title() === 'Your store is approved'
            && $n->tone() === 'success');
});

test('a rejection carries the reason, which is the whole point of it', function () {
    Notification::fake();
    [$vendor, $seller, $admin] = storeAndAdmin();

    $this->actingAs($admin)->patch(route('admin.vendors.status', $vendor), [
        'status' => 'rejected',
        'rejection_reason' => 'The GST number did not check out.',
    ])->assertRedirect();

    Notification::assertSentTo($seller, StoreStatusChanged::class,
        fn (StoreStatusChanged $n) => str_contains($n->body(), 'The GST number did not check out.')
            && $n->tone() === 'danger');
});

test('a suspension reaches the seller too', function () {
    Notification::fake();
    [$vendor, $seller, $admin] = storeAndAdmin();
    $vendor->update(['status' => 'approved']);

    $this->actingAs($admin)->patch(route('admin.vendors.status', $vendor), ['status' => 'suspended'])
        ->assertRedirect();

    Notification::assertSentTo($seller, StoreStatusChanged::class);
});

test('re-saving the same status is not news', function () {
    Notification::fake();
    [$vendor, $seller, $admin] = storeAndAdmin();
    $vendor->update(['status' => 'approved']);

    $this->actingAs($admin)->patch(route('admin.vendors.status', $vendor), ['status' => 'approved'])
        ->assertRedirect();

    Notification::assertNotSentTo($seller, StoreStatusChanged::class);
});

test('the news reaches every login on the store, and nobody else\'s', function () {
    Notification::fake();
    [$vendor, $seller, $admin] = storeAndAdmin();

    $colleague = User::factory()->create(['role' => 'vendor', 'vendor_id' => $vendor->id, 'is_active' => true]);
    $dormant = User::factory()->create(['role' => 'vendor', 'vendor_id' => $vendor->id, 'is_active' => false]);
    $stranger = User::factory()->create([
        'role' => 'vendor',
        'vendor_id' => Vendor::factory()->create()->id,
        'is_active' => true,
    ]);

    $this->actingAs($admin)->patch(route('admin.vendors.status', $vendor), ['status' => 'approved']);

    Notification::assertSentTo($seller, StoreStatusChanged::class);
    Notification::assertSentTo($colleague, StoreStatusChanged::class);
    Notification::assertNotSentTo($dormant, StoreStatusChanged::class);
    Notification::assertNotSentTo($stranger, StoreStatusChanged::class);
});

test('the store notification does not go through the role matrix', function () {
    Notification::fake();
    [$vendor, $seller, $admin] = storeAndAdmin();

    // A vendor never holds the `vendors` section, so section routing would
    // have dropped this on the floor.
    Roles::save('vendor', []);

    $this->actingAs($admin)->patch(route('admin.vendors.status', $vendor), ['status' => 'approved']);

    Notification::assertSentTo($seller, StoreStatusChanged::class);
});

test('a pending seller can read the approval through the API', function () {
    [$vendor, $seller, $admin] = storeAndAdmin();

    $this->actingAs($admin)->patch(route('admin.vendors.status', $vendor), ['status' => 'approved']);

    $this->app['auth']->forgetGuards();

    $this->withHeader('Authorization', 'Bearer '.$seller->createToken('phone')->plainTextToken)
        ->getJson(route('api.seller.notifications.index'))
        ->assertOk()
        ->assertJsonPath('data.0.kind', 'store-status-changed')
        ->assertJsonPath('data.0.title', 'Your store is approved')
        ->assertJsonPath('data.0.url', '/store');
});

/*
|--------------------------------------------------------------------------
| Cancellation and refund decisions
|--------------------------------------------------------------------------
*/

test('a seller hears the outcome of a cancellation', function () {
    Notification::fake();
    [$me, $store] = actingAsSeller();
    $admin = adminUser();
    $order = orderForStore($store);

    $cancellation = Cancellation::factory()->create(['order_id' => $order->id, 'status' => 'pending']);
    $cancellation->items()->create([
        'order_item_id' => $order->items->first()->id,
        'quantity' => 1,
        'amount' => 1000,
    ]);

    $this->actingAs($admin)
        ->patch(route('admin.cancellations.approve', $cancellation), ['review_note' => 'Agreed.'])
        ->assertRedirect();

    Notification::assertSentTo($me, CancellationDecided::class,
        fn (CancellationDecided $n) => str_contains($n->title(), 'approved')
            && $n->body() === 'Agreed.');
});

test('a rejected cancellation is news as well', function () {
    Notification::fake();
    [$me, $store] = actingAsSeller();
    $admin = adminUser();
    $order = orderForStore($store);

    $cancellation = Cancellation::factory()->create(['order_id' => $order->id, 'status' => 'pending']);
    $cancellation->items()->create([
        'order_item_id' => $order->items->first()->id,
        'quantity' => 1,
        'amount' => 1000,
    ]);

    $this->actingAs($admin)
        ->patch(route('admin.cancellations.reject', $cancellation), ['review_note' => 'Already shipped.'])
        ->assertRedirect();

    Notification::assertSentTo($me, CancellationDecided::class,
        fn (CancellationDecided $n) => str_contains($n->title(), 'rejected'));
});

test('a seller hears each step of a refund, money moving included', function () {
    Notification::fake();
    [$me, $store] = actingAsSeller();
    $admin = adminUser();
    $order = orderForStore($store);

    $refund = Refund::factory()->create(['order_id' => $order->id, 'status' => 'pending']);
    $refund->items()->create([
        'order_item_id' => $order->items->first()->id,
        'quantity' => 1,
        'amount' => 1000,
    ]);

    $this->actingAs($admin)->patch(route('admin.refunds.approve', $refund), [])->assertRedirect();
    Notification::assertSentTo($me, RefundDecided::class,
        fn (RefundDecided $n) => str_contains($n->title(), 'approved'));

    $this->actingAs($admin)->patch(route('admin.refunds.process', $refund), [])->assertRedirect();
    Notification::assertSentTo($me, RefundDecided::class,
        fn (RefundDecided $n) => str_contains($n->title(), 'paid out'));
});

test('a seller on another store hears nothing about either', function () {
    Notification::fake();
    [$me, $store] = actingAsSeller();
    $admin = adminUser();

    $stranger = User::factory()->create([
        'role' => 'vendor',
        'vendor_id' => Vendor::factory()->create(['status' => 'approved'])->id,
        'is_active' => true,
    ]);

    $order = orderForStore($store);
    $cancellation = Cancellation::factory()->create(['order_id' => $order->id, 'status' => 'pending']);
    $cancellation->items()->create([
        'order_item_id' => $order->items->first()->id,
        'quantity' => 1,
        'amount' => 1000,
    ]);

    $this->actingAs($admin)->patch(route('admin.cancellations.approve', $cancellation), []);

    Notification::assertNotSentTo($stranger, CancellationDecided::class);
});

/*
|--------------------------------------------------------------------------
| Payouts
|--------------------------------------------------------------------------
*/

test('a seller is told when a payout is actually paid', function () {
    Notification::fake();
    [$me, $store] = actingAsSeller();
    $admin = adminUser();

    $payout = VendorPayout::factory()->create(['vendor_id' => $store->id, 'status' => 'pending']);

    $this->actingAs($admin)
        ->patch(route('admin.payouts.status', $payout), ['status' => 'paid'])
        ->assertRedirect();

    Notification::assertSentTo($me, PayoutStatusChanged::class,
        fn (PayoutStatusChanged $n) => str_contains($n->title(), 'paid') && $n->tone() === 'success');
});

test('a failed payout says so, since the seller has to fix their details', function () {
    Notification::fake();
    [$me, $store] = actingAsSeller();
    $admin = adminUser();

    $payout = VendorPayout::factory()->create(['vendor_id' => $store->id, 'status' => 'pending']);

    $this->actingAs($admin)->patch(route('admin.payouts.status', $payout), ['status' => 'failed']);

    Notification::assertSentTo($me, PayoutStatusChanged::class,
        fn (PayoutStatusChanged $n) => $n->tone() === 'danger');
});

test('saving a payout on the status it already had is not news', function () {
    Notification::fake();
    [$me, $store] = actingAsSeller();
    $admin = adminUser();

    $payout = VendorPayout::factory()->create(['vendor_id' => $store->id, 'status' => 'paid']);

    $this->actingAs($admin)->patch(route('admin.payouts.status', $payout), ['status' => 'paid']);

    Notification::assertNotSentTo($me, PayoutStatusChanged::class);
});

/*
|--------------------------------------------------------------------------
| Team changes from the app
|--------------------------------------------------------------------------
*/

test('adding a login from the app tells the rest of the store', function () {
    Notification::fake();
    [$me, $store] = actingAsSeller();
    $colleague = User::factory()->create(['role' => 'vendor', 'vendor_id' => $store->id, 'is_active' => true]);

    $this->postJson(route('api.seller.team.store'), [
        'name' => 'Packing desk',
        'email' => 'packing@meera.test',
        'password' => 'a-good-password',
        'password_confirmation' => 'a-good-password',
    ])->assertCreated();

    Notification::assertSentTo($colleague, TeamMemberChanged::class);
    // Whoever did it does not need telling.
    Notification::assertNotSentTo($me, TeamMemberChanged::class);
});

test('deactivating and removing a login are announced too', function () {
    Notification::fake();
    [$me, $store] = actingAsSeller();
    $colleague = User::factory()->create(['role' => 'vendor', 'vendor_id' => $store->id, 'is_active' => true]);
    $target = User::factory()->create(['role' => 'vendor', 'vendor_id' => $store->id, 'is_active' => true]);

    $this->patchJson(route('api.seller.team.toggle', $target->id))->assertOk();
    Notification::assertSentTo($colleague, TeamMemberChanged::class);

    $this->deleteJson(route('api.seller.team.destroy', $target->id))->assertOk();

    Notification::assertSentToTimes($colleague, TeamMemberChanged::class, 2);
});

test('switching a colleague back on is not an alert', function () {
    Notification::fake();
    [$me, $store] = actingAsSeller();
    $colleague = User::factory()->create(['role' => 'vendor', 'vendor_id' => $store->id, 'is_active' => true]);
    $target = User::factory()->create(['role' => 'vendor', 'vendor_id' => $store->id, 'is_active' => false]);

    $this->patchJson(route('api.seller.team.toggle', $target->id))->assertOk();

    Notification::assertNotSentTo($colleague, TeamMemberChanged::class);
});

/*
|--------------------------------------------------------------------------
| Order status
|--------------------------------------------------------------------------
*/

test('a seller is told when the marketplace cancels an order they may be packing', function () {
    Notification::fake();
    [$me, $store] = actingAsSeller();
    $admin = adminUser();
    $order = orderForStore($store);

    $this->actingAs($admin)
        ->patch(route('admin.orders.status', $order), ['status' => 'cancelled'])
        ->assertRedirect();

    Notification::assertSentTo($me, OrderStatusChanged::class,
        fn (OrderStatusChanged $n) => str_contains($n->title(), 'cancelled')
            && $n->tone() === 'danger');
});

test('putting an order on hold, and delivering it, are both worth telling', function () {
    expect(OrderStatusChanged::worthTelling('processing', 'on_hold'))->toBeTrue()
        ->and(OrderStatusChanged::worthTelling('processing', 'completed'))->toBeTrue()
        ->and(OrderStatusChanged::worthTelling('processing', 'cancelled'))->toBeTrue();
});

test('the rest of the ladder stays quiet', function () {
    // Shipping is normally the seller's own doing, and `refunded` already has
    // RefundDecided speaking for it.
    expect(OrderStatusChanged::worthTelling('processing', 'shipped'))->toBeFalse()
        ->and(OrderStatusChanged::worthTelling('pending', 'processing'))->toBeFalse()
        ->and(OrderStatusChanged::worthTelling('processing', 'refunded'))->toBeFalse()
        // Saving the same status is never news.
        ->and(OrderStatusChanged::worthTelling('cancelled', 'cancelled'))->toBeFalse();
});

test('a status the seller does not need is not sent', function () {
    Notification::fake();
    [$me, $store] = actingAsSeller();
    $admin = adminUser();
    $order = orderForStore($store);

    $this->actingAs($admin)->patch(route('admin.orders.status', $order), ['status' => 'shipped']);

    Notification::assertNotSentTo($me, OrderStatusChanged::class);
});

test('a seller on another store hears nothing about the cancellation', function () {
    Notification::fake();
    [$me, $store] = actingAsSeller();
    $admin = adminUser();
    $stranger = User::factory()->create([
        'role' => 'vendor',
        'vendor_id' => Vendor::factory()->create(['status' => 'approved'])->id,
        'is_active' => true,
    ]);

    $this->actingAs($admin)->patch(route('admin.orders.status', orderForStore($store)), ['status' => 'cancelled']);

    Notification::assertNotSentTo($stranger, OrderStatusChanged::class);
});
