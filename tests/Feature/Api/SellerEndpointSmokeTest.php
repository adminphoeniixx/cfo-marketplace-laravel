<?php

use App\Models\Cancellation;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DeviceToken;
use App\Models\Order;
use App\Models\Product;
use App\Models\PushSubscription;
use App\Models\Refund;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\TaxClass;
use App\Models\Ticket;
use App\Models\User;
use App\Models\VendorPayout;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Testing\TestResponse;

/*
| Every seller endpoint, called once, with data behind it.
|
| The twin of `CustomerEndpointSmokeTest`, for the panel the seller app talks
| to: one call per route, each inside its own savepoint, and a last test that
| reads the router so a new route cannot be added without a call here.
*/

beforeEach(function () {
    config()->set('services.bunnycdn', [
        'storage_zone' => 'test-zone',
        'api_key' => 'test-key',
        'region' => '',
        'host' => 'storage.bunnycdn.com',
        'pull_zone_url' => 'https://test-zone.b-cdn.net/',
        'token_auth_key' => null,
        'url_ttl' => 604800,
        'prefix' => 'cfo',
        'connect_timeout' => 5,
        'timeout' => 20,
    ]);

    Http::fake(['storage.bunnycdn.com/*' => Http::response('', 201)]);

    $this->fix = storeFixture();
});

/**
 * One store with a business behind it: a catalogue, an order to pack, a
 * payout, a return, a colleague and a shipping rate.
 *
 * @return array<string, mixed>
 */
function storeFixture(): array
{
    [$seller, $store] = actingAsSeller(['name' => 'Meera Textiles', 'city' => 'Chennai']);
    $seller->forceFill(['password' => 'seller-password', 'email' => 'meera@example.com'])->save();

    // A second device, so `devices.revoke` has something to revoke that is not
    // the token this test is holding.
    $otherToken = $seller->createToken('old tablet');

    $colleague = new User;
    $colleague->forceFill([
        'name' => 'Ravi Kumar',
        'email' => 'ravi@example.com',
        'password' => 'a-good-password',
        'role' => 'vendor',
        'vendor_id' => $store->id,
        'is_active' => true,
        'email_verified_at' => now(),
    ])->save();

    $category = Category::factory()->create(['name' => 'Handloom']);
    $taxClass = TaxClass::factory()->create();
    $product = Product::factory()->for($store)->create([
        'name' => 'Kanchipuram silk saree',
        'status' => 'active',
        'price' => 4999,
        'stock_quantity' => 12,
        'track_inventory' => true,
        'category_id' => $category->id,
    ]);

    $customer = Customer::factory()->create(['status' => 'active']);
    $order = orderForStore($store);
    $order->update(['customer_id' => $customer->id]);
    $item = $order->items->first();

    $payout = VendorPayout::factory()->create(['vendor_id' => $store->id]);

    $cancellation = Cancellation::create([
        'number' => Cancellation::nextNumber(),
        'order_id' => $order->id,
        'customer_id' => $customer->id,
        'reason' => 'out_of_stock',
        'restock' => true,
        'refund_requested' => false,
        'requested_by' => 'vendor',
        'status' => 'pending',
        'scope' => 'partial',
        'total_amount' => 1000,
    ]);
    $cancellation->items()->create(['order_item_id' => $item->id, 'quantity' => 1, 'amount' => 1000]);

    $refund = Refund::create([
        'number' => Refund::nextNumber(),
        'order_id' => $order->id,
        'customer_id' => $customer->id,
        'reason' => 'damaged',
        'method' => 'original',
        'restock' => true,
        'shipping_amount' => 0,
        'adjustment_amount' => 0,
        'total_amount' => 1000,
        'status' => 'pending',
    ]);
    $refund->items()->create(['order_item_id' => $item->id, 'quantity' => 1, 'amount' => 1000]);

    // Two piles of post: a shopper writing to this store, and this store
    // writing to the marketplace.
    $incoming = Ticket::factory()->create([
        'customer_id' => $customer->id,
        'audience' => 'vendor',
        'vendor_id' => $store->id,
        'opened_by' => 'customer',
        'subject' => 'Where is my parcel?',
    ]);
    $incoming->addMessage('It was due on Tuesday.', from: $customer);

    $zone = ShippingZone::factory()->create(['is_active' => true]);
    $rate = ShippingRate::factory()->create([
        'shipping_zone_id' => $zone->id,
        'vendor_id' => $store->id,
    ]);

    $seller->notify(new class extends Notification
    {
        /** @return array<int, string> */
        public function via(object $notifiable): array
        {
            return ['database'];
        }

        /** @return array<string, mixed> */
        public function toArray(object $notifiable): array
        {
            return ['title' => 'New order', 'body' => 'One saree to pack', 'kind' => 'order'];
        }
    });

    DeviceToken::remember($seller, 'fcm-store-token', 'android', 'Pixel');

    PushSubscription::create([
        'user_id' => $seller->id,
        'endpoint' => 'https://push.example.test/store-1',
        'endpoint_hash' => PushSubscription::hashFor('https://push.example.test/store-1'),
        'public_key' => 'p256dh-key',
        'auth_token' => 'auth-key',
        'content_encoding' => 'aesgcm',
    ]);

    return [
        'seller' => $seller,
        'store' => $store,
        'colleague' => $colleague,
        'category' => $category,
        'tax_class' => $taxClass,
        'product' => $product,
        'customer' => $customer,
        'order' => $order,
        'item' => $item,
        'payout' => $payout,
        'ticket' => $incoming,
        'cancellation' => $cancellation,
        'refund' => $refund,
        'zone' => $zone,
        'rate' => $rate,
        'notification' => $seller->notifications()->firstOrFail(),
        'other_token_id' => $otherToken->accessToken->id,
    ];
}

/**
 * Every call, keyed by route name: [method, url, payload] and, where it is not
 * 200, the status the app should expect.
 *
 * @param  array<string, mixed>  $f
 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3?: int}>
 */
function storeCalls(array $f): array
{
    return [
        // Getting in, and the devices already in.
        'api.seller.register' => ['POST', route('api.seller.register'), [
            'name' => 'Anita Rao', 'email' => 'anita@example.com',
            'password' => 'a-good-password', 'password_confirmation' => 'a-good-password',
            'store_name' => 'Anita Handlooms', 'device_name' => 'Pixel 8',
        ], 201],
        'api.seller.login' => ['POST', route('api.seller.login'), [
            'email' => 'meera@example.com', 'password' => 'seller-password', 'device_name' => 'Pixel 8',
        ]],
        'api.seller.forgot-password' => ['POST', route('api.seller.forgot-password'), [
            'email' => 'meera@example.com',
        ]],
        'api.seller.reset-password' => ['POST', route('api.seller.reset-password'), []],
        'api.seller.refresh' => ['POST', route('api.seller.refresh'), []],
        'api.seller.logout' => ['POST', route('api.seller.logout'), []],
        'api.seller.logout-all' => ['POST', route('api.seller.logout-all'), []],
        'api.seller.devices' => ['GET', route('api.seller.devices'), []],
        'api.seller.devices.revoke' => ['DELETE', route('api.seller.devices.revoke', $f['other_token_id']), []],

        // The seller, and their store.
        'api.seller.me' => ['GET', route('api.seller.me'), []],
        'api.seller.me.update' => ['PUT', route('api.seller.me.update'), [
            'name' => 'Meera Iyer', 'email' => 'meera@example.com', 'phone' => '9000000002',
        ]],
        'api.seller.me.password' => ['PUT', route('api.seller.me.password'), [
            'current_password' => 'seller-password',
            'password' => 'another-good-one', 'password_confirmation' => 'another-good-one',
        ]],
        'api.seller.store' => ['GET', route('api.seller.store'), []],
        'api.seller.store.update' => ['PUT', route('api.seller.store.update'), [
            'name' => 'Meera Textiles', 'city' => 'Chennai', 'state' => 'Tamil Nadu', 'country' => 'IN',
        ]],

        // The numbers.
        'api.seller.dashboard' => ['GET', route('api.seller.dashboard'), []],
        'api.seller.analytics.sales' => ['GET', route('api.seller.analytics.sales'), []],
        'api.seller.analytics.report' => ['GET', route('api.seller.analytics.report'), []],
        'api.seller.analytics.export' => ['GET', route('api.seller.analytics.export', 'orders'), []],

        // What the product form needs before it can draw itself.
        'api.seller.catalog.categories' => ['GET', route('api.seller.catalog.categories'), []],
        'api.seller.catalog.attributes' => ['GET', route('api.seller.catalog.attributes'), []],
        'api.seller.catalog.tax-classes' => ['GET', route('api.seller.catalog.tax-classes'), []],
        'api.seller.catalog.options' => ['GET', route('api.seller.catalog.options'), []],
        'api.seller.delivery-partners' => ['GET', route('api.seller.delivery-partners'), []],

        // The catalogue.
        'api.seller.products.index' => ['GET', route('api.seller.products.index'), []],
        'api.seller.products.store' => ['POST', route('api.seller.products.store'), [
            'name' => 'Chettinad cotton saree', 'type' => 'simple', 'price' => 1899,
            'status' => 'active', 'track_inventory' => true, 'stock_quantity' => 5,
            'category_id' => $f['category']->id,
        ], 201],
        'api.seller.products.show' => ['GET', route('api.seller.products.show', $f['product']->id), []],
        'api.seller.products.update' => ['PUT', route('api.seller.products.update', $f['product']->id), [
            'name' => 'Kanchipuram silk saree', 'type' => 'simple', 'price' => 5299,
            'status' => 'active', 'track_inventory' => true, 'stock_quantity' => 12,
        ]],
        'api.seller.products.status' => ['PATCH', route('api.seller.products.status', $f['product']->id), [
            'status' => 'draft',
        ]],
        'api.seller.products.stock' => ['PATCH', route('api.seller.products.stock', $f['product']->id), [
            'stock_quantity' => 20, 'low_stock_threshold' => 3,
        ]],
        'api.seller.products.bulk' => ['POST', route('api.seller.products.bulk'), [
            'action' => 'draft', 'ids' => [$f['product']->id],
        ]],
        'api.seller.products.destroy' => ['DELETE', route('api.seller.products.destroy', $f['product']->id), []],
        'api.seller.uploads.store' => ['POST', route('api.seller.uploads.store'), [
            'file' => UploadedFile::fake()->image('saree.jpg'), 'folder' => 'products',
        ], 201],

        // Orders, and packing one.
        'api.seller.orders.index' => ['GET', route('api.seller.orders.index'), []],
        'api.seller.orders.summary' => ['GET', route('api.seller.orders.summary'), []],
        'api.seller.orders.show' => ['GET', route('api.seller.orders.show', $f['order']->id), []],
        'api.seller.orders.customers' => ['GET', route('api.seller.orders.customers'), []],
        'api.seller.orders.sellable' => ['GET', route('api.seller.orders.sellable'), []],
        'api.seller.orders.store' => ['POST', route('api.seller.orders.store'), [
            'customer_id' => $f['customer']->id,
            'email' => 'buyer@example.test',
            'status' => 'processing',
            'payment_status' => 'paid',
            'payment_method' => 'upi',
            'items' => [['product_id' => $f['product']->id, 'quantity' => 1]],
        ], 201],
        'api.seller.orders.fulfill' => ['POST', route('api.seller.orders.fulfill', $f['order']->id), [
            'items' => [['id' => $f['item']->id, 'quantity' => 1]],
            'tracking_number' => 'TRK-SMOKE-9', 'carrier' => 'shiprocket',
        ]],
        'api.seller.orders.notes' => ['POST', route('api.seller.orders.notes', $f['order']->id), [
            'note' => 'Packed and handed over.',
        ], 201],
        // 409 rather than 200: this fixture's order was never booked with a
        // courier, so there is genuinely no label to print — which is the
        // answer the endpoint is meant to give rather than an error.
        'api.seller.orders.label' => ['GET', route('api.seller.orders.label', $f['order']->id), [], 409],
        'api.seller.orders.invoice' => ['GET', route('api.seller.orders.invoice', $f['order']->id), []],

        // Money out.
        'api.seller.payouts.index' => ['GET', route('api.seller.payouts.index'), []],
        'api.seller.payouts.show' => ['GET', route('api.seller.payouts.show', $f['payout']->id), []],
        'api.seller.payouts.commission-invoice' => ['GET', route('api.seller.payouts.commission-invoice', $f['payout']->id), [], 409],
        'api.seller.payouts.earnings' => ['GET', route('api.seller.payouts.earnings'), []],

        // Cancellations and returns, from this side of the counter.
        'api.seller.cancellations.index' => ['GET', route('api.seller.cancellations.index'), []],
        'api.seller.cancellations.show' => ['GET', route('api.seller.cancellations.show', $f['cancellation']->id), []],
        'api.seller.cancellations.store' => ['POST', route('api.seller.cancellations.store'), [
            'order_id' => $f['order']->id,
            'reason' => 'out_of_stock',
            'note' => 'The last one was damaged in storage.',
            'items' => [['order_item_id' => $f['item']->id, 'quantity' => 1]],
        ], 201],
        'api.seller.cancellations.respond' => ['POST', route('api.seller.cancellations.respond', $f['cancellation']->id), [
            'note' => 'Restocked and refunded.',
        ], 201],
        'api.seller.refunds.index' => ['GET', route('api.seller.refunds.index'), []],
        'api.seller.refunds.show' => ['GET', route('api.seller.refunds.show', $f['refund']->id), []],
        'api.seller.refunds.respond' => ['POST', route('api.seller.refunds.respond', $f['refund']->id), [
            'note' => 'Return received in good order.',
        ], 201],

        // Support: what shoppers asked this store, and what it asked the
        // marketplace.
        'api.seller.support.tickets.index' => ['GET', route('api.seller.support.tickets.index'), []],
        'api.seller.support.tickets.store' => ['POST', route('api.seller.support.tickets.store'), [
            'subject' => 'August payout has not landed',
            'message' => 'The panel says paid but nothing has arrived.',
            'category' => 'payment',
        ], 201],
        'api.seller.support.tickets.show' => ['GET', route('api.seller.support.tickets.show', $f['ticket']->number), []],
        'api.seller.support.tickets.reply' => ['POST', route('api.seller.support.tickets.reply', $f['ticket']->number), [
            'message' => 'Posted this morning, tracking to follow.',
        ]],

        // Delivery.
        'api.seller.shipping.zones' => ['GET', route('api.seller.shipping.zones'), []],
        'api.seller.shipping.rates.index' => ['GET', route('api.seller.shipping.rates.index'), []],
        'api.seller.shipping.rates.store' => ['POST', route('api.seller.shipping.rates.store'), [
            'shipping_zone_id' => $f['zone']->id, 'name' => 'Standard', 'type' => 'flat',
            'rate' => 49, 'delivery_days_min' => 3, 'delivery_days_max' => 6,
        ], 201],
        'api.seller.shipping.rates.update' => ['PUT', route('api.seller.shipping.rates.update', $f['rate']->id), [
            'shipping_zone_id' => $f['zone']->id, 'name' => 'Standard', 'type' => 'flat', 'rate' => 59,
        ]],
        'api.seller.shipping.rates.destroy' => ['DELETE', route('api.seller.shipping.rates.destroy', $f['rate']->id), []],

        // Colleagues.
        'api.seller.team.index' => ['GET', route('api.seller.team.index'), []],
        'api.seller.team.store' => ['POST', route('api.seller.team.store'), [
            'name' => 'Divya Menon', 'email' => 'divya@example.com',
            'password' => 'a-good-password', 'password_confirmation' => 'a-good-password',
        ], 201],
        'api.seller.team.update' => ['PUT', route('api.seller.team.update', $f['colleague']->id), [
            'name' => 'Ravi Kumar', 'email' => 'ravi@example.com', 'phone' => '9000000003',
        ]],
        'api.seller.team.toggle' => ['PATCH', route('api.seller.team.toggle', $f['colleague']->id), []],
        'api.seller.team.destroy' => ['DELETE', route('api.seller.team.destroy', $f['colleague']->id), []],

        // The bell.
        'api.seller.notifications.index' => ['GET', route('api.seller.notifications.index'), []],
        'api.seller.notifications.unread' => ['GET', route('api.seller.notifications.unread'), []],
        'api.seller.notifications.read' => ['POST', route('api.seller.notifications.read', $f['notification']->id), []],
        'api.seller.notifications.read-all' => ['POST', route('api.seller.notifications.read-all'), []],
        'api.seller.notifications.destroy' => ['DELETE', route('api.seller.notifications.destroy', $f['notification']->id), []],
        'api.seller.push.settings' => ['GET', route('api.seller.push.settings'), []],
        'api.seller.push.subscribe' => ['POST', route('api.seller.push.subscribe'), [
            'endpoint' => 'https://push.example.test/store-2',
            'keys' => ['p256dh' => 'p256dh-key', 'auth' => 'auth-key'],
        ], 201],
        'api.seller.push.unsubscribe' => ['DELETE', route('api.seller.push.unsubscribe'), [
            'endpoint' => 'https://push.example.test/store-1',
        ]],
        // 201 here, 200 on the shopper's identical call — an old inconsistency
        // between the two APIs, pinned so it cannot drift further unnoticed.
        'api.seller.push.device.register' => ['POST', route('api.seller.push.device.register'), [
            'token' => 'fcm-new-store-token', 'platform' => 'android', 'device_name' => 'Pixel 8',
        ], 201],
        'api.seller.push.device.forget' => ['DELETE', route('api.seller.push.device.forget'), [
            'token' => 'fcm-store-token',
        ]],
    ];
}

test('every seller endpoint answers with real data behind it', function () {
    $calls = storeCalls($this->fix);
    $failures = [];

    foreach ($calls as $name => $call) {
        [$method, $url, $payload] = $call;
        $expected = $call[3] ?? 200;

        // A savepoint per call, so a DELETE never decides what the next call
        // sees and the order of the list means nothing.
        DB::beginTransaction();

        try {
            app('auth')->forgetGuards();

            $response = storeCall($this->fix, $name, $method, $url, $payload);

            if ($response->getStatusCode() !== $expected) {
                $failures[$name] = $method.' '.parse_url($url, PHP_URL_PATH)
                    .' → '.$response->getStatusCode().', wanted '.$expected.'. '
                    .mb_substr((string) $response->getContent(), 0, 200);
            }
        } finally {
            DB::rollBack();
        }
    }

    expect($failures)->toBe([]);
});

/**
 * Make one call. The password reset needs a token that was emailed, and the
 * upload needs a multipart body rather than JSON.
 *
 * @param  array<string, mixed>  $f
 * @param  array<string, mixed>  $payload
 */
function storeCall(array $f, string $name, string $method, string $url, array $payload): TestResponse
{
    if ($name === 'api.seller.reset-password') {
        return test()->postJson($url, [
            'token' => Password::broker()->createToken($f['seller']),
            'email' => $f['seller']->email,
            'password' => 'a-brand-new-one',
            'password_confirmation' => 'a-brand-new-one',
        ]);
    }

    if ($name === 'api.seller.uploads.store') {
        return test()->post($url, $payload, ['Accept' => 'application/json']);
    }

    // The CSV export streams, so it is fetched rather than decoded as JSON.
    if ($name === 'api.seller.analytics.export') {
        return test()->get($url);
    }

    return test()->json($method, $url, $payload);
}

test('no seller endpoint is left without a call', function () {
    $registered = collect(Router::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->uri(), 'api/seller/'))
        ->map(fn ($route) => (string) $route->getName())
        ->unique()
        ->sort()
        ->values();

    $called = collect(array_keys(storeCalls($this->fix)))->sort()->values();

    expect($registered->diff($called)->all())->toBe([])
        ->and($called->diff($registered)->all())->toBe([]);
});
