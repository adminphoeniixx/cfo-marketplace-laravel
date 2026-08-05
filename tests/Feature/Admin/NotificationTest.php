<?php

use App\Jobs\SendWebPush;
use App\Models\Order;
use App\Models\Product;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\OrderPlaced;
use App\Notifications\TeamMemberChanged;
use App\Services\Notifier;
use App\Support\Roles;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

test('guests have no notification centre', function () {
    $this->get(route('admin.notifications.index'))->assertRedirect(route('login'));
});

test('the centre lists your own notifications and nobody else\'s', function () {
    $me = actingAsAdmin();
    $someoneElse = User::factory()->create(['role' => 'admin']);

    $me->notify(new TeamMemberChanged('Priya', 'added', 'staff', 'Aisha'));
    $someoneElse->notify(new TeamMemberChanged('Rahul', 'added', 'staff', 'Aisha'));

    $this->get(route('admin.notifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/notifications/Index')
            ->has('notifications.data', 1)
            ->where('notifications.data.0.title', 'Priya was added to the team')
            ->where('notifications.data.0.read', false)
            ->where('counts.unread', 1)
        );
});

test('the bell count rides along on every admin page', function () {
    $me = actingAsAdmin();
    $me->notify(new TeamMemberChanged('Priya', 'added', 'staff', 'Aisha'));

    $this->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('bell.unread', 1)
            ->has('bell.recent', 1)
        );
});

test('the bell survives the notification centre\'s own props', function () {
    $me = actingAsAdmin();
    $me->notify(new TeamMemberChanged('Priya', 'added', 'staff', 'Aisha'));

    // The page ships a `notifications` paginator of its own; the bell payload
    // has to keep its own name or the badge blanks out on this screen.
    $this->get(route('admin.notifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('bell.unread', 1)
            ->has('notifications.data', 1)
        );
});

test('notifications can be read, cleared and deleted', function () {
    $me = actingAsAdmin();
    $me->notify(new TeamMemberChanged('Priya', 'added', 'staff', 'Aisha'));
    $me->notify(new TeamMemberChanged('Rahul', 'added', 'staff', 'Aisha'));

    $first = $me->notifications()->first();

    $this->post(route('admin.notifications.read', $first->id));
    expect($me->unreadNotifications()->count())->toBe(1);

    $this->post(route('admin.notifications.read-all'))->assertSessionHas('success');
    expect($me->unreadNotifications()->count())->toBe(0);

    // Clearing read leaves the pile empty once everything has been read.
    $this->delete(route('admin.notifications.clear-read'))->assertSessionHas('success');
    expect($me->notifications()->count())->toBe(0);
});

test('you cannot read or delete somebody else\'s notification', function () {
    actingAsAdmin();
    $other = User::factory()->create(['role' => 'admin']);
    $other->notify(new TeamMemberChanged('Priya', 'added', 'staff', 'Aisha'));

    $theirs = $other->notifications()->first();

    $this->post(route('admin.notifications.read', $theirs->id));
    $this->delete(route('admin.notifications.destroy', $theirs->id));

    expect($other->unreadNotifications()->count())->toBe(1);
});

test('only roles holding the section are told', function () {
    $actor = adminUser();
    $admin = User::factory()->create(['role' => 'admin']);
    $staff = User::factory()->create(['role' => 'staff']);
    $manager = User::factory()->create(['role' => 'manager']);

    // Team notifications belong to a section only admins hold by default.
    Notifier::send(TeamMemberChanged::for($staff, 'added', $actor), $actor);

    expect($admin->unreadNotifications()->count())->toBe(1)
        ->and($staff->unreadNotifications()->count())->toBe(0)
        ->and($manager->unreadNotifications()->count())->toBe(0)
        // The person who did it is not told about their own action.
        ->and($actor->unreadNotifications()->count())->toBe(0);
});

test('granting a section through the matrix widens the audience', function () {
    $actor = adminUser();
    $manager = User::factory()->create(['role' => 'manager']);

    Roles::save('manager', [...Roles::DEFAULTS['manager'], 'team']);

    Notifier::send(TeamMemberChanged::for($manager, 'added', $actor), $actor);

    expect($manager->unreadNotifications()->count())->toBe(1);
});

test('deactivated staff are not notified', function () {
    $actor = adminUser();
    $sleeping = User::factory()->create(['role' => 'admin', 'is_active' => false]);

    Notifier::send(TeamMemberChanged::for($sleeping, 'added', $actor), $actor);

    expect($sleeping->unreadNotifications()->count())->toBe(0);
});

test('a vendor login only hears about its own store', function () {
    $actor = adminUser();
    $mine = Vendor::factory()->create();
    $theirs = Vendor::factory()->create();

    $ourSeller = User::factory()->create(['role' => 'vendor', 'vendor_id' => $mine->id]);
    $otherSeller = User::factory()->create(['role' => 'vendor', 'vendor_id' => $theirs->id]);

    $order = Order::factory()->create();
    $order->items()->create([
        'vendor_id' => $mine->id,
        'name' => 'Thing',
        'unit_price' => 100,
        'quantity' => 1,
        'total' => 100,
    ]);

    Notifier::send(new OrderPlaced($order->load('items', 'customer')), $actor);

    expect($ourSeller->unreadNotifications()->count())->toBe(1)
        ->and($otherSeller->unreadNotifications()->count())->toBe(0);
});

test('raising an order tells the rest of the team', function () {
    $actor = actingAsAdmin();
    $watcher = User::factory()->create(['role' => 'admin']);
    $vendor = Vendor::factory()->create();

    $product = Product::factory()->create([
        'vendor_id' => $vendor->id,
        'price' => 500,
        'stock_quantity' => 50,
        'track_inventory' => true,
    ]);

    $this->post(route('admin.orders.store'), [
        'vendor_id' => $vendor->id,
        'email' => 'buyer@example.test',
        'status' => 'pending',
        'payment_status' => 'pending',
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ])->assertRedirect();

    $notification = $watcher->unreadNotifications()->first();

    expect($notification)->not->toBeNull()
        ->and($notification->data['kind'])->toBe('order-placed')
        ->and($notification->data['section'])->toBe('orders')
        // The admin who raised it is not told about their own order.
        ->and($actor->unreadNotifications()->count())->toBe(0);
});

test('a browser can subscribe to and unsubscribe from push', function () {
    config()->set('webpush.enabled', true);
    $me = actingAsAdmin();

    $payload = [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
        'keys' => ['p256dh' => 'public-key-value', 'auth' => 'auth-token-value'],
    ];

    $this->postJson(route('admin.notifications.push.subscribe'), $payload)
        ->assertOk()
        ->assertJson(['subscribed' => true]);

    expect(PushSubscription::where('user_id', $me->id)->count())->toBe(1);

    // Subscribing the same browser twice must not stack up rows.
    $this->postJson(route('admin.notifications.push.subscribe'), $payload)->assertOk();
    expect(PushSubscription::where('user_id', $me->id)->count())->toBe(1);

    $this->deleteJson(route('admin.notifications.push.unsubscribe'), [
        'endpoint' => $payload['endpoint'],
    ])->assertOk();

    expect(PushSubscription::where('user_id', $me->id)->count())->toBe(0);
});

test('push stays quiet when no VAPID keys are configured', function () {
    config()->set('webpush.enabled', false);

    $actor = adminUser();
    $watcher = User::factory()->create(['role' => 'admin']);
    PushSubscription::create([
        'user_id' => $watcher->id,
        'endpoint' => 'https://example.test/push/1',
        'endpoint_hash' => PushSubscription::hashFor('https://example.test/push/1'),
        'public_key' => 'k',
        'auth_token' => 'a',
    ]);

    Queue::fake();

    Notifier::send(TeamMemberChanged::for($watcher, 'added', $actor), $actor);

    // The database leg still runs; only the push job is skipped.
    expect($watcher->unreadNotifications()->count())->toBe(1);
    Queue::assertNothingPushed();
});

test('a configured push queues one job per subscribed browser', function () {
    config()->set('webpush.enabled', true);

    $actor = adminUser();
    $watcher = User::factory()->create(['role' => 'admin']);

    foreach (['https://example.test/push/1', 'https://example.test/push/2'] as $endpoint) {
        PushSubscription::create([
            'user_id' => $watcher->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hashFor($endpoint),
            'public_key' => 'k',
            'auth_token' => 'a',
        ]);
    }

    Queue::fake();

    Notifier::send(TeamMemberChanged::for($watcher, 'added', $actor), $actor);

    Queue::assertPushed(SendWebPush::class, 2);
});
