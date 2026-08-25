<?php

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Notifications\Notification;
use Inertia\Testing\AssertableInertia as Assert;

function sellerWithStore(array $attributes = []): array
{
    $store = Vendor::factory()->create([
        'status' => 'approved',
        'commission_rate' => 12.5,
        ...$attributes,
    ]);

    $user = User::factory()->create([
        'role' => 'vendor',
        'vendor_id' => $store->id,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    test()->actingAs($user);

    return [$user, $store];
}

test('a seller edits their own shopfront and payout details', function () {
    [, $store] = sellerWithStore();

    $this->get(route('seller.settings.store'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('seller/settings/Store')
            ->where('store.id', $store->id)
            ->where('marketplace.commission_rate', '12.50')
        );

    $this->put(route('seller.settings.store'), [
        'name' => 'Kanchi Looms',
        'store_email' => 'hello@kanchilooms.test',
        'city' => 'Kanchipuram',
        'payout_method' => 'upi',
    ])->assertSessionHas('success');

    expect($store->fresh())
        ->name->toBe('Kanchi Looms')
        ->city->toBe('Kanchipuram')
        ->payout_method->toBe('upi');
});

test('commission, status and rating are not the seller\'s to set', function () {
    [, $store] = sellerWithStore(['status' => 'approved', 'commission_rate' => 12.5]);

    $this->put(route('seller.settings.store'), [
        'name' => 'Kanchi Looms',
        // None of these are in the validated set, so they are dropped.
        'commission_rate' => 0,
        'commission_type' => 'flat',
        'status' => 'suspended',
        'rating' => 5,
    ])->assertSessionHas('success');

    expect($store->fresh())
        ->commission_rate->toBe('12.50')
        ->status->toBe('approved');
});

test('the payout method must be one the marketplace pays out through', function () {
    sellerWithStore();

    $this->put(route('seller.settings.store'), [
        'name' => 'Kanchi Looms',
        'payout_method' => 'suitcase-of-cash',
    ])->assertSessionHasErrors('payout_method');
});

test('a seller reads their own notifications inside their own panel', function () {
    [$user] = sellerWithStore();

    $user->notify(new class extends Notification
    {
        /** @return list<string> */
        public function via(object $notifiable): array
        {
            return ['database'];
        }

        /** @return array<string, string> */
        public function toDatabase(object $notifiable): array
        {
            return [
                'title' => 'Your store was approved',
                'body' => 'You can start listing.',
                'url' => '/seller/products',
                'kind' => 'store',
                'tone' => 'success',
            ];
        }
    });

    $this->get(route('seller.notifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('seller/notifications/Index')
            ->has('notifications.data', 1)
            ->where('notifications.data.0.title', 'Your store was approved')
            ->where('counts.unread', 1)
        );

    $this->post(route('seller.notifications.read-all'))->assertSessionHas('success');

    expect($user->fresh()->unreadNotifications()->count())->toBe(0);
});

test('a seller never sees another seller\'s notifications', function () {
    [$user] = sellerWithStore();

    $stranger = User::factory()->create(['role' => 'vendor']);
    $stranger->notify(new class extends Notification
    {
        /** @return list<string> */
        public function via(object $notifiable): array
        {
            return ['database'];
        }

        /** @return array<string, string> */
        public function toDatabase(object $notifiable): array
        {
            return ['title' => 'Not for you', 'body' => '', 'url' => '/', 'kind' => 'x', 'tone' => 'info'];
        }
    });

    $this->get(route('seller.notifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('notifications.data', 0));

    expect($user->notifications()->count())->toBe(0);
});
