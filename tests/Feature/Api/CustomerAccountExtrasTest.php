<?php

use App\Models\Banner;
use App\Models\Customer;
use App\Models\Faq;
use App\Models\LegalPage;
use App\Models\Order;
use App\Models\ProductReview;
use App\Models\Setting;

/*
| The account screen's counts, the switches behind its notification rows, the
| door out of the marketplace, and the words the app used to carry itself.
*/

beforeEach(function () {
    $this->customer = actingAsCustomer(['first_name' => 'Priya']);
});

test('the account screen gets its counts in one call', function () {
    $product = sellableProduct();
    $other = sellableProduct();

    $order = Order::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => 'completed',
        'delivered_at' => now()->subDay(),
    ]);
    foreach ([$product, $other] as $sold) {
        $order->items()->create([
            'product_id' => $sold->id, 'vendor_id' => $sold->vendor_id, 'name' => $sold->name,
            'unit_price' => 1000, 'quantity' => 1, 'total' => 1000,
        ]);
    }

    ProductReview::create([
        'product_id' => $product->id, 'customer_id' => $this->customer->id,
        'order_id' => $order->id, 'rating' => 5, 'status' => 'published', 'is_verified' => true,
    ]);

    $this->customer->addresses()->create([
        'first_name' => 'Priya', 'address_line1' => '42 Beach Road', 'city' => 'Chennai',
    ]);
    $this->customer->wishlistItems()->create(['product_id' => $other->id]);
    $this->customer->refreshOrderStats();

    $this->getJson(route('api.customer.me.summary'))
        ->assertOk()
        ->assertJsonPath('data.orders_count', 1)
        ->assertJsonPath('data.addresses_count', 1)
        ->assertJsonPath('data.wishlist_count', 1)
        ->assertJsonPath('data.reviews_written_count', 1)
        // The second line of that order is delivered and unrated.
        ->assertJsonPath('data.reviews_pending_count', 1)
        // The token this test is holding.
        ->assertJsonPath('data.devices_count', 1);
});

test('notification switches are remembered by the marketplace, not the phone', function () {
    $this->getJson(route('api.customer.notification-preferences'))
        ->assertOk()
        ->assertJsonPath('data.push_enabled', true)
        ->assertJsonPath('data.order_updates', true)
        // Deals are marketing, and marketing is opt-in.
        ->assertJsonPath('data.deals_price_drops', false);

    $this->putJson(route('api.customer.notification-preferences.update'), [
        'deals_price_drops' => true,
        'sms_order_updates' => false,
        'email_marketing' => true,
    ])->assertOk()
        ->assertJsonPath('data.deals_price_drops', true)
        ->assertJsonPath('data.sms_order_updates', false)
        ->assertJsonPath('data.email_marketing', true);

    // `email_marketing` is the old column under the name the screen uses.
    expect($this->customer->fresh()->accepts_marketing)->toBeTrue()
        ->and($this->customer->fresh()->push_enabled)->toBeTrue();
});

test('closing the account signs every device out and frees the email', function () {
    $email = $this->customer->email;
    $this->customer->createToken('old tablet');

    $this->deleteJson(route('api.customer.me.destroy'))
        ->assertOk()
        ->assertJsonPath('deleted', true);

    // Soft-deleted: the orders behind it are the marketplace's own records.
    expect(Customer::find($this->customer->id))->toBeNull()
        ->and(Customer::withTrashed()->find($this->customer->id)->trashed())->toBeTrue()
        ->and($this->customer->tokens()->count())->toBe(0);

    // The address is released, so the same person can sign up again.
    $this->postJson(route('api.customer.auth.register'), [
        'first_name' => 'Priya', 'email' => $email,
        'password' => 'a-good-password', 'password_confirmation' => 'a-good-password',
        'device_name' => 'Pixel 8',
    ])->assertCreated();
});

test('an order still on the way keeps the account open', function () {
    Order::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => 'processing',
    ]);

    $this->deleteJson(route('api.customer.me.destroy'))
        ->assertStatus(422)
        ->assertJsonValidationErrors('account');

    expect(Customer::find($this->customer->id))->not->toBeNull();
});

test('the home screen is sent its banners rather than carrying them', function () {
    Banner::factory()->create(['title' => 'Festive edit', 'position' => 0]);
    Banner::factory()->expired()->create(['title' => 'Last summer', 'position' => 1]);
    Banner::factory()->create(['title' => 'Switched off', 'is_active' => false, 'position' => 2]);

    $this->getJson(route('api.customer.home'))
        ->assertOk()
        // Only the live one: a sale that ended takes itself down.
        ->assertJsonCount(1, 'banners')
        ->assertJsonPath('banners.0.title', 'Festive edit')
        ->assertJsonPath('banners.0.deeplink_route', 'category')
        ->assertJsonPath('banners.0.deeplink_params.category_id', 1);
});

test('support config says how to reach a human, and only offers chat when there is one', function () {
    Faq::factory()->create(['question' => 'When will my order arrive?', 'position' => 0]);
    Faq::factory()->create(['question' => 'Hidden', 'is_active' => false]);

    Setting::put('store_phone', '1800 200 3000');
    Setting::put('support_hours', 'Every day, 9am to 9pm');

    $this->getJson(route('api.customer.support.config'))
        ->assertOk()
        ->assertJsonPath('data.chat_enabled', false)
        ->assertJsonPath('data.chat_session_url', null)
        ->assertJsonPath('data.phone', '1800 200 3000')
        ->assertJsonPath('data.hours', 'Every day, 9am to 9pm')
        ->assertJsonCount(1, 'data.faq')
        ->assertJsonPath('data.faq.0.question', 'When will my order arrive?');
});

test('chat is offered only once there is somewhere to send them', function () {
    Setting::put('support_chat_url', 'https://chat.example.test/session');

    $this->getJson(route('api.customer.support.config'))
        ->assertOk()
        ->assertJsonPath('data.chat_enabled', true)
        ->assertJsonPath('data.chat_provider', 'web')
        ->assertJsonPath('data.chat_session_url', 'https://chat.example.test/session');
});

test('a legal page nobody has written is not a page', function () {
    // The five slugs ship with the schema, unpublished and empty.
    $this->getJson(route('api.customer.legal.show', 'privacy'))->assertNotFound();
    $this->getJson(route('api.customer.legal.index'))->assertOk()->assertJsonCount(0, 'data');

    LegalPage::where('slug', 'privacy')->update([
        'body' => 'What we keep, and for how long.',
        'is_published' => true,
    ]);

    $this->getJson(route('api.customer.legal.show', 'privacy'))
        ->assertOk()
        ->assertJsonPath('data.title', 'Privacy policy')
        ->assertJsonPath('data.body', 'What we keep, and for how long.')
        ->assertJsonPath('data.slug', 'privacy');

    $this->getJson(route('api.customer.legal.index'))->assertOk()->assertJsonCount(1, 'data');
});

test('app config carries the seller link the app used to hardcode', function () {
    Setting::put('seller_onboarding_url', 'https://sell.example.test');

    $this->getJson(route('api.customer.app-config'))
        ->assertOk()
        ->assertJsonPath('data.seller_onboarding_url', 'https://sell.example.test')
        ->assertJsonPath('data.currency', 'INR');
});
