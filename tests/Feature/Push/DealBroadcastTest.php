<?php

use App\Jobs\SendDealBroadcast;
use App\Jobs\SendFcmMessage;
use App\Models\Customer;
use App\Models\CustomerDeviceToken;
use App\Models\PushBroadcast;
use Illuminate\Support\Facades\Queue;

/*
| The one message nobody asked for individually.
|
| Everything else the shopper app sends is a consequence of something they did.
| This is the marketplace speaking first, so it is the only notification gated
| on a preference that is off by default — and the only one that keeps a record
| of who sent it, because "who sent that to forty thousand people" is a
| question that eventually gets asked.
*/

function shopperWantingDeals(bool $wants = true, array $attributes = []): Customer
{
    $customer = Customer::factory()->create([
        'status' => 'active', 'notify_deals' => $wants, ...$attributes,
    ]);

    CustomerDeviceToken::remember($customer, 'tok-'.$customer->id);

    return $customer;
}

test('the panel counts who it is about to reach, and it is not everybody', function () {
    actingAsAdmin();

    shopperWantingDeals();
    shopperWantingDeals();
    shopperWantingDeals(false);
    shopperWantingDeals(true, ['status' => 'blocked']);

    $this->get('/admin/broadcasts')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/broadcasts/Index')->where('reach', 2));
});

test('sending records who sent it, and queues the delivery', function () {
    Queue::fake();
    $admin = actingAsAdmin();

    shopperWantingDeals();
    shopperWantingDeals();

    $this->post('/admin/broadcasts', [
        'heading' => 'Weekend on the house',
        'message' => 'Free delivery on everything until Sunday night.',
        'link' => '/products?filter=deals',
    ])->assertRedirect();

    $broadcast = PushBroadcast::firstOrFail();

    expect($broadcast->heading)->toBe('Weekend on the house')
        ->and($broadcast->recipients)->toBe(2)
        ->and($broadcast->user_id)->toBe($admin->id);

    Queue::assertPushed(SendDealBroadcast::class, 1);
});

test('a web address is refused where an in-app route is wanted', function () {
    actingAsAdmin();

    $this->post('/admin/broadcasts', [
        'heading' => 'Sale', 'message' => 'Everything must go.',
        'link' => 'https://example.com/deals',
    ])->assertSessionHasErrors('link');

    expect(PushBroadcast::count())->toBe(0);
});

test('a heading longer than a lock screen is refused', function () {
    actingAsAdmin();

    $this->post('/admin/broadcasts', [
        'heading' => str_repeat('a', 61),
        'message' => 'Everything must go.',
    ])->assertSessionHasErrors('heading');
});

test('only the shoppers who asked for deals actually get one', function () {
    Queue::fake();
    config(['firebase.enabled' => true, 'firebase.project_id' => 'qa']);

    $wants = shopperWantingDeals();
    $doesNot = shopperWantingDeals(false);

    $broadcast = PushBroadcast::create([
        'heading' => 'Weekend on the house',
        'message' => 'Free delivery until Sunday.',
        'recipients' => 1,
    ]);

    (new SendDealBroadcast($broadcast->id))->handle();

    expect($wants->notifications()->count())->toBe(1)
        ->and($wants->notifications()->first()->data['kind'])->toBe('deal')
        // Not merely unpushed — not in their feed either. Muting deals means
        // not seeing them, not seeing them silently.
        ->and($doesNot->notifications()->count())->toBe(0);

    Queue::assertPushed(SendFcmMessage::class, 1);
});

test('a shopper who wants deals but switched push off still gets the feed row', function () {
    Queue::fake();
    config(['firebase.enabled' => true, 'firebase.project_id' => 'qa']);

    $customer = shopperWantingDeals(true, ['push_enabled' => false]);

    $broadcast = PushBroadcast::create([
        'heading' => 'Sale', 'message' => 'Everything must go.', 'recipients' => 1,
    ]);
    (new SendDealBroadcast($broadcast->id))->handle();

    expect($customer->notifications()->count())->toBe(1);
    Queue::assertNothingPushed();
});

test('a role without the customers section cannot broadcast to anybody', function () {
    actingAsAdmin();
    $staff = \App\Models\User::factory()->create(['role' => 'staff', 'is_active' => true]);

    // Take the section away, and the screen and the send both close with it.
    $this->put(route('admin.roles.update', 'staff'), ['sections' => ['orders']])
        ->assertSessionHas('success');

    $this->actingAs($staff);

    $this->get('/admin/broadcasts')->assertForbidden();
    $this->post('/admin/broadcasts', [
        'heading' => 'Sale', 'message' => 'Everything must go.',
    ])->assertForbidden();

    expect(PushBroadcast::count())->toBe(0);
});
