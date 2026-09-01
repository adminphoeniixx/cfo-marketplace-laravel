<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductReview;
use Illuminate\Notifications\Notification;

beforeEach(function () {
    $this->customer = actingAsCustomer(['first_name' => 'Priya', 'email' => 'priya@example.com']);
});

test('the profile reads and writes', function () {
    $this->getJson(route('api.customer.me'))
        ->assertOk()
        ->assertJsonPath('data.first_name', 'Priya')
        ->assertJsonPath('data.has_password', false);

    $this->putJson(route('api.customer.me.update'), [
        'first_name' => 'Priyanka',
        'gender' => 'female',
        'accepts_marketing' => true,
    ])->assertOk()->assertJsonPath('data.first_name', 'Priyanka');

    expect($this->customer->fresh()->accepts_marketing)->toBeTrue();
});

test('changing the email un-verifies it', function () {
    $this->customer->forceFill(['email_verified' => true])->save();

    $this->putJson(route('api.customer.me.update'), ['email' => 'new@example.com'])->assertOk();

    expect($this->customer->fresh()->email_verified)->toBeFalse();
});

test('an email already taken is refused', function () {
    Customer::factory()->create(['email' => 'taken@example.com']);

    $this->putJson(route('api.customer.me.update'), ['email' => 'taken@example.com'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

test('a shopper with no password can set one, and it signs other devices out', function () {
    $other = $this->customer->createToken('old tablet');

    $this->putJson(route('api.customer.me.password'), [
        'password' => 'a-good-password',
        'password_confirmation' => 'a-good-password',
    ])->assertOk();

    expect($this->customer->fresh()->password)->not->toBeNull()
        ->and($this->customer->tokens()->count())->toBe(1);
});

test('changing an existing password needs the current one', function () {
    $customer = actingAsCustomer();
    $customer->forceFill(['password' => 'a-good-password'])->save();

    $this->putJson(route('api.customer.me.password'), [
        'current_password' => 'not-it',
        'password' => 'another-good-one',
        'password_confirmation' => 'another-good-one',
    ])->assertStatus(422)->assertJsonValidationErrors('current_password');
});

test('addresses can be added, defaulted and deleted', function () {
    $first = $this->postJson(route('api.customer.addresses.store'), [
        'label' => 'Home',
        'first_name' => 'Priya',
        'address_line1' => '42 Beach Road',
        'city' => 'Chennai',
        'state' => 'Tamil Nadu',
        'postcode' => '600090',
    ])->assertCreated()->json('data');

    // The first one saved is the one we deliver to.
    expect($first['is_default_shipping'])->toBeTrue();

    $second = $this->postJson(route('api.customer.addresses.store'), [
        'label' => 'Work',
        'first_name' => 'Priya',
        'address_line1' => 'Tidel Park',
        'city' => 'Chennai',
    ])->assertCreated()->json('data');

    $this->patchJson(route('api.customer.addresses.default', $second['id']))->assertOk();

    $addresses = collect($this->getJson(route('api.customer.addresses.index'))->json('data'))->keyBy('id');

    expect($addresses[$second['id']]['is_default_shipping'])->toBeTrue()
        ->and($addresses[$first['id']]['is_default_shipping'])->toBeFalse();

    $this->deleteJson(route('api.customer.addresses.destroy', $second['id']))->assertOk();

    // Deleting the default promotes what is left, so orders always have
    // somewhere to go.
    expect($this->customer->addresses()->where('is_default_shipping', true)->count())->toBe(1);
});

test('the last address cannot be deleted', function () {
    $address = $this->postJson(route('api.customer.addresses.store'), [
        'first_name' => 'Priya', 'address_line1' => 'Somewhere', 'city' => 'Chennai',
    ])->json('data');

    $this->deleteJson(route('api.customer.addresses.destroy', $address['id']))->assertStatus(422);
});

test("another shopper's address is a 404", function () {
    $stranger = Customer::factory()->create();
    $theirs = $stranger->addresses()->create([
        'first_name' => 'Someone', 'address_line1' => 'Elsewhere', 'city' => 'Pune',
    ]);

    $this->putJson(route('api.customer.addresses.update', $theirs->id), [
        'first_name' => 'Hijacked', 'address_line1' => 'Mine now', 'city' => 'Chennai',
    ])->assertNotFound();

    $this->deleteJson(route('api.customer.addresses.destroy', $theirs->id))->assertNotFound();
});

test('only someone who received the item may rate it', function () {
    $product = sellableProduct();

    $this->postJson(route('api.customer.reviews.store', $product->id), ['rating' => 5])
        ->assertStatus(422);

    $order = Order::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => 'completed',
        'delivered_at' => now()->subDay(),
    ]);
    $order->items()->create([
        'product_id' => $product->id,
        'vendor_id' => $product->vendor_id,
        'name' => $product->name,
        'unit_price' => 1000, 'quantity' => 1, 'total' => 1000,
    ]);

    $this->postJson(route('api.customer.reviews.store', $product->id), [
        'rating' => 4,
        'title' => 'Lovely',
        'body' => 'Exactly as photographed.',
    ])->assertCreated()
        ->assertJsonPath('data.rating', 4)
        ->assertJsonPath('data.is_verified', true)
        // First name and a last initial, never the full name.
        ->assertJsonPath('data.author', $this->customer->first_name.' '.mb_substr($this->customer->last_name, 0, 1).'.');

    expect((float) $product->fresh()->rating)->toBe(4.0);
});

test('rating twice edits the first one instead of stacking', function () {
    $product = sellableProduct();
    $order = Order::factory()->create([
        'customer_id' => $this->customer->id, 'status' => 'completed', 'delivered_at' => now()->subDay(),
    ]);
    $order->items()->create([
        'product_id' => $product->id, 'vendor_id' => $product->vendor_id, 'name' => $product->name,
        'unit_price' => 1, 'quantity' => 1, 'total' => 1,
    ]);

    $this->postJson(route('api.customer.reviews.store', $product->id), ['rating' => 2])->assertCreated();
    $this->postJson(route('api.customer.reviews.store', $product->id), ['rating' => 5])->assertCreated();

    expect(ProductReview::count())->toBe(1)
        ->and((float) $product->fresh()->rating)->toBe(5.0);
});

test('published reviews are public and mine are listed apart', function () {
    $product = sellableProduct();
    ProductReview::factory()->create(['product_id' => $product->id, 'rating' => 5]);
    ProductReview::factory()->create(['product_id' => $product->id, 'status' => 'hidden']);

    $this->getJson(route('api.customer.products.reviews', $product->id))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->getJson(route('api.customer.reviews.mine'))
        ->assertOk()
        ->assertJsonCount(0, 'written');
});

test('the wishlist toggles and survives a second tap', function () {
    $product = sellableProduct();

    $this->postJson(route('api.customer.wishlist.store'), ['product_id' => $product->id])->assertCreated();
    $this->postJson(route('api.customer.wishlist.store'), ['product_id' => $product->id])->assertCreated();

    $this->getJson(route('api.customer.wishlist.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.is_wishlisted', true);

    $this->deleteJson(route('api.customer.wishlist.destroy', $product->id))->assertOk();

    $this->getJson(route('api.customer.wishlist.index'))->assertJsonCount(0, 'data');
});

test('notifications read, count and clear', function () {
    $this->customer->notify(new class extends Notification
    {
        public function via(object $notifiable): array
        {
            return ['database'];
        }

        public function toArray(object $notifiable): array
        {
            return ['title' => 'Your order shipped', 'body' => 'TRK-1', 'kind' => 'order'];
        }
    });

    $this->getJson(route('api.customer.notifications.index'))
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Your order shipped')
        ->assertJsonPath('unread_count', 1);

    $this->postJson(route('api.customer.notifications.read-all'))->assertOk();

    $this->getJson(route('api.customer.notifications.unread'))->assertJsonPath('unread', 0);
});

test('a malformed notification id is a 404, not a 500', function () {
    // Caught on the live server: notification ids are uuids, and on Postgres a
    // non-uuid reaches the database and blows up. SQLite let it through as a
    // plain no-match, so the tests never saw it.
    $this->postJson('/api/customer/notifications/999/read')->assertNotFound();
    $this->deleteJson('/api/customer/notifications/not-a-uuid')->assertNotFound();
});

test('push tokens register and forget', function () {
    $this->postJson(route('api.customer.push.device.register'), [
        'token' => 'fcm-token-1',
        'platform' => 'android',
    ])->assertOk();

    expect($this->customer->fresh()->id)->not->toBeNull();
    $this->assertDatabaseHas('customer_device_tokens', ['customer_id' => $this->customer->id]);

    $this->deleteJson(route('api.customer.push.device.forget'), ['token' => 'fcm-token-1'])->assertOk();

    $this->assertDatabaseCount('customer_device_tokens', 0);
});

test('the reference lists say exactly what the API will accept', function () {
    $this->getJson(route('api.customer.reference'))
        ->assertOk()
        ->assertJsonStructure([
            'payment_methods', 'delivery_partners',
            'cancellation_reasons', 'refund_reasons', 'refund_methods', 'return_window_days',
        ]);
});
