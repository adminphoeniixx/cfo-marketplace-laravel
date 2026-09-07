<?php

use App\Models\DeliveryPartner;
use App\Models\Order;
use App\Models\Vendor;
use App\Notifications\ParcelNeedsAttention;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/*
| The three things a parcel does other than arrive.
|
| A delivery attempted and failed, a parcel coming back, one that is lost —
| until now all three read as "shipped, not delivered yet", indefinitely. That
| is how a marketplace finds out about a returned parcel a month later from a
| stock count.
|
| Delhivery is the harder case and the reason `StatusType` is read at all: a
| parcel coming *back* to the seller reports "In Transit" and then "Delivered",
| exactly like one going out. Only the journey code tells them apart, and
| "Delivered" on a return is the one status a shopper must never be shown as
| good news.
*/

beforeEach(function () {
    config([
        'services.delhivery.token' => 'test-token',
        'services.delhivery.base_url' => 'https://staging-express.delhivery.com',
        'services.delhivery.pickup_name' => 'CFO Noida',
    ]);

    DeliveryPartner::where('name', 'Delhivery')->update(['driver' => 'delhivery', 'is_active' => true]);

    $this->store = Vendor::factory()->create(['status' => 'approved']);

    // `Notifier` sends to whoever holds the section, so a marketplace with no
    // staff at all would make every notification assertion below vacuous.
    adminUser();
});

/** An order in flight, waiting to hear from the courier. */
function parcelInFlight(array $attributes = []): Order
{
    $order = Order::factory()->create([
        'status' => 'shipped',
        'payment_status' => 'paid',
        'carrier' => 'Delhivery',
        'tracking_number' => 'AWB-5150',
        'shipped_at' => now()->subDays(2),
        ...$attributes,
    ]);

    $order->items()->create([
        'vendor_id' => test()->store->id, 'name' => 'Kanchipuram silk saree',
        'sku' => 'SKU-'.$order->id, 'unit_price' => 1000, 'quantity' => 1, 'total' => 1000,
    ]);

    return $order;
}

/** Delhivery's tracking shape, with the journey code that changes everything. */
function delhiveryTracking(string $status, string $type = 'UD', array $scans = []): array
{
    return ['ShipmentData' => [['Shipment' => [
        'AWB' => 'AWB-5150',
        'Status' => [
            'Status' => $status,
            'StatusType' => $type,
            'StatusLocation' => 'Chennai_Hub',
            'StatusDateTime' => '2026-09-06T09:15:00',
        ],
        'Scans' => array_map(fn (array $scan) => ['ScanDetail' => $scan], $scans),
    ]]]];
}

test('a failed delivery attempt is counted and announced', function () {
    Notification::fake();

    Http::fake(['*/api/v1/packages/json/*' => Http::response(delhiveryTracking('Pending', 'UD', [[
        'Scan' => 'Pending',
        'Instructions' => 'Consignee unavailable',
        'ScannedLocation' => 'Chennai_Hub',
        'ScanDateTime' => '2026-09-06T09:15:00',
    ]]))]);

    $order = parcelInFlight();

    $this->artisan('shipments:sync')->assertSuccessful();

    $order->refresh();

    // "Pending" alone is in transit; the instructions on the scan are what say
    // a delivery was tried.
    expect($order->delivery_attempts)->toBe(1);

    Notification::assertSentTimes(ParcelNeedsAttention::class, 0);
});

test('a parcel on its way back is not a parcel on its way out', function () {
    Notification::fake();

    Http::fake(['*/api/v1/packages/json/*' => Http::response(delhiveryTracking('In Transit', 'RT'))]);

    $order = parcelInFlight();

    $this->artisan('shipments:sync')->assertSuccessful();

    $order->refresh();

    expect($order->shipment_status)->toBe('returning')
        ->and($order->delivered_at)->toBeNull()
        ->and($order->returned_at)->toBeNull()
        // The sale is not cancelled and not refunded — somebody decides that.
        ->and($order->status)->toBe('shipped');

    Notification::assertSentTimes(ParcelNeedsAttention::class, 1);
});

test('"delivered" on a return journey is never shown as delivered', function () {
    Notification::fake();

    Http::fake(['*/api/v1/packages/json/*' => Http::response(delhiveryTracking('Delivered', 'RT'))]);

    $order = parcelInFlight();

    $this->artisan('shipments:sync')->assertSuccessful();

    $order->refresh();

    expect($order->shipment_status)->toBe('returned')
        ->and($order->returned_at)->not->toBeNull()
        // The one assertion this whole feature exists for.
        ->and($order->delivered_at)->toBeNull()
        ->and($order->status)->not->toBe('completed')
        // The seller owes the goods no longer; they are in the room.
        ->and($order->fulfillment_status)->toBe('unfulfilled');
});

test('a cash order that came back is never marked paid', function () {
    Http::fake(['*/api/v1/packages/json/*' => Http::response(delhiveryTracking('Delivered', 'RT'))]);

    $order = parcelInFlight(['payment_status' => 'pending', 'payment_method' => 'Cash on Delivery']);

    $this->artisan('shipments:sync')->assertSuccessful();

    expect($order->fresh()->payment_status)->toBe('pending');
});

test('a returned parcel is not asked about again', function () {
    Http::fake(['*/api/v1/packages/json/*' => Http::response(delhiveryTracking('Delivered', 'RT'))]);

    parcelInFlight();

    $this->artisan('shipments:sync')->assertSuccessful();
    $this->artisan('shipments:sync')->assertSuccessful();

    Http::assertSentCount(1);
});

test('a parcel still coming back is asked about until it lands', function () {
    Http::fake(['*/api/v1/packages/json/*' => Http::response(delhiveryTracking('In Transit', 'RT'))]);

    parcelInFlight();

    $this->artisan('shipments:sync')->assertSuccessful();
    $this->artisan('shipments:sync')->assertSuccessful();

    Http::assertSentCount(2);
});

test('a lost parcel raises an alarm rather than waiting for ever', function () {
    Notification::fake();

    Http::fake(['*/api/v1/packages/json/*' => Http::response(delhiveryTracking('Lost'))]);

    $order = parcelInFlight();

    $this->artisan('shipments:sync')->assertSuccessful();

    expect($order->fresh()->shipment_status)->toBe('lost');

    Notification::assertSentTimes(ParcelNeedsAttention::class, 1);
});

test('the same bad news twice is announced once', function () {
    Notification::fake();

    Http::fake(['*/api/v1/packages/json/*' => Http::response(delhiveryTracking('In Transit', 'RT'))]);

    parcelInFlight();

    $this->artisan('shipments:sync')->assertSuccessful();
    $this->artisan('shipments:sync')->assertSuccessful();

    Notification::assertSentTimes(ParcelNeedsAttention::class, 1);
});

test('an ordinary delivery still completes the order and settles the cash', function () {
    Http::fake(['*/api/v1/packages/json/*' => Http::response(delhiveryTracking('Delivered', 'DL'))]);

    $order = parcelInFlight(['payment_status' => 'pending', 'payment_method' => 'Cash on Delivery']);

    $this->artisan('shipments:sync')->assertSuccessful();

    $order->refresh();

    expect($order->shipment_status)->toBe('delivered')
        ->and($order->delivered_at)->not->toBeNull()
        ->and($order->status)->toBe('completed')
        ->and($order->payment_status)->toBe('paid')
        ->and($order->returned_at)->toBeNull();
});

/*
| And what the shopper sees, which is the point of all of it.
|
| "Out for delivery" used to be reachable only retrospectively — the screen
| drew the order's own timestamps, so a step the marketplace had no way of
| knowing about could only be filled in once the parcel had already arrived.
*/

test('the tracking screen reaches out for delivery on the morning it happens', function () {
    $customer = actingAsCustomer();

    $order = parcelInFlight([
        'customer_id' => $customer->id,
        'shipment_status' => 'out_for_delivery',
    ]);

    $steps = collect($this->getJson(route('api.customer.orders.track', ltrim($order->number, '#')))
        ->assertOk()->json('data.events'))->keyBy('key');

    expect($steps['out_for_delivery']['state'])->toBe('done')
        ->and($steps['delivered']['state'])->toBe('current');
});

test('a failed attempt is said plainly rather than left as "on its way"', function () {
    $customer = actingAsCustomer();

    $order = parcelInFlight([
        'customer_id' => $customer->id,
        'shipment_status' => 'undelivered',
        'delivery_attempts' => 2,
    ]);

    $steps = collect($this->getJson(route('api.customer.orders.track', ltrim($order->number, '#')))
        ->assertOk()->json('data.events'))->keyBy('key');

    // A shopper who believes a parcel is still coming does not answer the
    // phone to the courier.
    expect($steps['out_for_delivery']['description'])
        ->toBe('Delivery was attempted and could not be completed');
});

test('a parcel coming back replaces "delivered" rather than sitting above it', function () {
    $customer = actingAsCustomer();

    $order = parcelInFlight([
        'customer_id' => $customer->id,
        'shipment_status' => 'returning',
    ]);

    $steps = collect($this->getJson(route('api.customer.orders.track', ltrim($order->number, '#')))
        ->assertOk()->json('data.events'))->keyBy('key');

    expect($steps->has('returning'))->toBeTrue()
        ->and($steps['returning']['label'])->toBe('On its way back to the seller')
        // A hollow "Delivered" under it would read as "still coming".
        ->and($steps->has('delivered'))->toBeFalse();
});

test('an alert on the timeline does not make the next run repeat every scan', function () {
    Http::fake(['*/api/v1/packages/json/*' => Http::response(delhiveryTracking('In Transit', 'RT', [[
        'Scan' => 'In Transit',
        'Instructions' => 'Returning to origin',
        'ScannedLocation' => 'Chennai_Hub',
        'ScanDateTime' => '2026-09-06T09:15:00',
    ]]))]);

    $order = parcelInFlight();

    $this->artisan('shipments:sync')->assertSuccessful();
    $this->artisan('shipments:sync')->assertSuccessful();

    // One scan, one alert, and the alert — which carries no scan time — must
    // not read as "nothing recorded yet" on the second run.
    expect($order->events()->where('type', 'shipment')->count())->toBe(2);
});
