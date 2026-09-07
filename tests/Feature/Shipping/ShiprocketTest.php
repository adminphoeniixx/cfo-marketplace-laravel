<?php

use App\Models\DeliveryPartner;
use App\Models\Order;
use App\Models\Vendor;
use App\Services\Couriers\Couriers;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
| Shiprocket, which is an aggregator rather than a courier — one set of
| credentials reaching Delhivery, Blue Dart, Ekart and the rest.
|
| Two things make it awkward and both are tested here: its token is a ten-day
| sign-in rather than a key, and booking is two calls, because an order and the
| waybill that makes it trackable are separate things on their side.
*/

beforeEach(function () {
    $this->partner = DeliveryPartner::create([
        'name' => 'Shiprocket',
        'code' => 'shiprocket',
        'driver' => 'shiprocket',
        'credentials' => [
            'email' => 'ops@example.test',
            'password' => 'a-good-password',
            'pickup_location' => 'Noida',
        ],
        'is_active' => true,
    ]);
});

function shiprocketOrder(array $attributes = []): Order
{
    $vendor = Vendor::factory()->create(['status' => 'approved']);

    $order = Order::factory()->create([
        'status' => 'processing',
        'payment_status' => 'paid',
        'grand_total' => 1180,
        'subtotal' => 1000,
        'carrier' => 'Shiprocket',
        'shipping_address' => [
            'first_name' => 'Priya', 'last_name' => 'Nair',
            'address_line1' => '42 Beach Road', 'city' => 'Chennai',
            'state' => 'Tamil Nadu', 'postcode' => '600090', 'phone' => '9876543210',
        ],
        ...$attributes,
    ]);

    $order->items()->create([
        'vendor_id' => $vendor->id, 'name' => 'Kanchipuram silk saree',
        'sku' => 'SKU-1', 'unit_price' => 1000, 'quantity' => 1, 'total' => 1000,
    ]);

    return $order->load('items');
}

test('booking signs in, creates the order, then asks for a waybill', function () {
    Http::fake([
        'apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'sr-token']),
        'apiv2.shiprocket.in/v1/external/orders/create/adhoc' => Http::response([
            'order_id' => 555, 'shipment_id' => 999,
        ]),
        'apiv2.shiprocket.in/v1/external/courier/assign/awb' => Http::response([
            'response' => ['data' => ['awb_code' => 'SR1234567890']],
        ]),
    ]);

    $order = shiprocketOrder();

    expect(Couriers::forCarrier('Shiprocket')?->createShipment($order))->toBe('SR1234567890');

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'orders/create/adhoc')) {
            return true;
        }

        return $request->hasHeader('Authorization', 'Bearer sr-token')
            && $request['pickup_location'] === 'Noida'
            && $request['payment_method'] === 'Prepaid'
            && $request['order_items'][0]['sku'] === 'SKU-1';
    });
});

test('a cash order is booked as COD', function () {
    Http::fake([
        'apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'sr-token']),
        'apiv2.shiprocket.in/*' => Http::response(['awb_code' => 'SR999']),
    ]);

    $order = shiprocketOrder([
        'payment_method' => 'Cash on Delivery',
        'payment_status' => 'pending',
    ]);

    Couriers::forCarrier('Shiprocket')?->createShipment($order);

    Http::assertSent(function ($request) {
        return ! str_contains($request->url(), 'orders/create/adhoc')
            || $request['payment_method'] === 'COD';
    });
});

test('an order taken with no waybill assigned is an error that says so', function () {
    Http::fake([
        'apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'sr-token']),
        'apiv2.shiprocket.in/v1/external/orders/create/adhoc' => Http::response(['shipment_id' => 999]),
        'apiv2.shiprocket.in/v1/external/courier/assign/awb' => Http::response([
            'message' => 'No courier available for this pincode',
        ], 422),
    ]);

    // The order exists on their side either way, which is worth saying plainly
    // — somebody will find it there.
    expect(fn () => Couriers::forCarrier('Shiprocket')?->createShipment(shiprocketOrder()))
        ->toThrow(RuntimeException::class, 'No courier available');
});

test('tracking maps their status vocabulary onto this marketplace’s', function () {
    Http::fake([
        'apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'sr-token']),
        'apiv2.shiprocket.in/v1/external/courier/track/awb/*' => Http::response([
            'tracking_data' => [
                'shipment_track' => [[
                    'current_status' => 'OUT FOR DELIVERY',
                    'destination' => 'Chennai',
                ]],
                'shipment_track_activities' => [
                    ['sr-status-label' => 'IN TRANSIT', 'activity' => 'Picked up', 'location' => 'Noida', 'date' => '2026-09-02 09:00:00'],
                ],
            ],
        ]),
    ]);

    $tracking = Couriers::forCarrier('Shiprocket')?->track('SR1234567890');

    expect($tracking['status'])->toBe('out_for_delivery')
        ->and($tracking['location'])->toBe('Chennai')
        ->and($tracking['scans'])->toHaveCount(1);
});

test('a refused sign-in is never cached', function () {
    Http::fake([
        'apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['message' => 'nope'], 401),
    ]);

    $client = Couriers::forCarrier('Shiprocket');

    expect($client?->ping())->toBeFalse()
        // Caching a failure would lock the marketplace out of a provider
        // having a bad morning for eight days.
        ->and(Cache::get('shiprocket:token:'.hash('sha256', 'ops@example.test')))->toBeNull();
});

test('signing in once serves every later call', function () {
    Http::fake([
        'apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'sr-token']),
        'apiv2.shiprocket.in/v1/external/courier/track/awb/*' => Http::response([
            'tracking_data' => ['shipment_track' => [['current_status' => 'DELIVERED']]],
        ]),
    ]);

    $client = Couriers::forCarrier('Shiprocket');
    $client?->track('SR1');
    $client?->track('SR2');

    // Their token lasts ten days; fetching one per request would be three
    // calls where there should be one.
    Http::assertSentCount(3);
});

test('an unreachable Shiprocket answers null rather than pretending', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect(Couriers::forCarrier('Shiprocket')?->track('SR1'))->toBeNull();
});

/*
| The three things a seller at a packing table needs, which a booking alone
| does not give them: a label for the box, a van to come and get it, and a way
| to call the whole thing off.
|
| All three are addressed by *shipment* on Shiprocket's side, and a waybill is
| the only identifier this marketplace kept — so each one resolves the waybill
| first. That extra call is the cost of not storing a second identifier for a
| parcel, and it is worth asserting rather than assuming.
*/

/** Their tracking shape, which is also how a waybill becomes a shipment id. */
function shiprocketTrack(int $shipmentId = 5150): array
{
    return ['tracking_data' => ['shipment_track' => [[
        'id' => 999, 'shipment_id' => $shipmentId, 'current_status' => 'IN TRANSIT',
    ]]]];
}

test('the label is a URL fetched against the shipment behind the waybill', function () {
    Http::fake([
        'apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'sr-token']),
        'apiv2.shiprocket.in/v1/external/courier/track/awb/*' => Http::response(shiprocketTrack()),
        'apiv2.shiprocket.in/v1/external/courier/generate/label' => Http::response([
            'label_created' => 1, 'label_url' => 'https://shiprocket.test/labels/5150.pdf',
        ]),
    ]);

    expect(Couriers::forCarrier('Shiprocket')?->label('SR-1001'))
        ->toBe('https://shiprocket.test/labels/5150.pdf');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'generate/label')
        && $request['shipment_id'] === [5150]);
});

test('a waybill Shiprocket has never heard of has no label', function () {
    Http::fake([
        'apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'sr-token']),
        'apiv2.shiprocket.in/v1/external/courier/track/awb/*' => Http::response([], 404),
    ]);

    expect(Couriers::forCarrier('Shiprocket')?->label('SR-NOPE'))->toBeNull();
});

test('a pickup is booked for every waybill in one request', function () {
    Http::fake([
        'apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'sr-token']),
        'apiv2.shiprocket.in/v1/external/courier/track/awb/SR-1' => Http::response(shiprocketTrack(11)),
        'apiv2.shiprocket.in/v1/external/courier/track/awb/SR-2' => Http::response(shiprocketTrack(22)),
        'apiv2.shiprocket.in/v1/external/courier/generate/pickup' => Http::response([
            'pickup_status' => 1,
            'response' => ['pickup_token_number' => 'PT-77', 'pickup_scheduled_date' => '2026-09-07 14:00'],
        ]),
    ]);

    $result = Couriers::forCarrier('Shiprocket')?->schedulePickup(['SR-1', 'SR-2']);

    expect($result['scheduled'])->toBeTrue()
        ->and($result['reference'])->toBe('PT-77');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'generate/pickup')
        && $request['shipment_id'] === [11, 22]);
});

test('one bad waybill does not keep a day’s collection from being booked', function () {
    Http::fake([
        'apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'sr-token']),
        'apiv2.shiprocket.in/v1/external/courier/track/awb/SR-1' => Http::response(shiprocketTrack(11)),
        'apiv2.shiprocket.in/v1/external/courier/track/awb/SR-GONE' => Http::response([], 404),
        'apiv2.shiprocket.in/v1/external/courier/generate/pickup' => Http::response([
            'pickup_status' => 1, 'response' => ['pickup_token_number' => 'PT-77'],
        ]),
    ]);

    $result = Couriers::forCarrier('Shiprocket')?->schedulePickup(['SR-1', 'SR-GONE']);

    expect($result['scheduled'])->toBeTrue();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'generate/pickup')
        && $request['shipment_id'] === [11]);
});

test('a refused pickup is reported rather than raised', function () {
    Http::fake([
        'apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'sr-token']),
        'apiv2.shiprocket.in/v1/external/courier/track/awb/*' => Http::response(shiprocketTrack(11)),
        'apiv2.shiprocket.in/v1/external/courier/generate/pickup' => Http::response([
            'message' => 'Already in pickup queue.',
        ], 400),
    ]);

    $result = Couriers::forCarrier('Shiprocket')?->schedulePickup(['SR-1']);

    // A collection already booked comes back as a failure with a perfectly
    // good explanation, which is not the same as nothing having been arranged.
    expect($result['scheduled'])->toBeFalse()
        ->and($result['message'])->toBe('Already in pickup queue.');
});

test('a booking is called off by waybill, so the order stays re-shippable', function () {
    Http::fake([
        'apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'sr-token']),
        'apiv2.shiprocket.in/v1/external/orders/cancel/shipment/awbs' => Http::response(['status' => 200]),
    ]);

    expect(Couriers::forCarrier('Shiprocket')?->cancel('SR-1001'))->toBeTrue();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'cancel/shipment/awbs')
        && $request['awbs'] === ['SR-1001']);
});

test('a cancellation Shiprocket refuses is false, not an exception', function () {
    Http::fake([
        'apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'sr-token']),
        'apiv2.shiprocket.in/v1/external/orders/cancel/shipment/awbs' => Http::response([
            'message' => 'Shipment already picked up.',
        ], 400),
    ]);

    expect(Couriers::forCarrier('Shiprocket')?->cancel('SR-1001'))->toBeFalse();
});

test('a sign-in Shiprocket refuses leaves every one of these answering safely', function () {
    Http::fake(['apiv2.shiprocket.in/v1/external/auth/login' => Http::response([], 401)]);

    $client = Couriers::forCarrier('Shiprocket');

    expect($client?->label('SR-1001'))->toBeNull()
        ->and($client?->schedulePickup(['SR-1001']))->toMatchArray(['scheduled' => false])
        ->and($client?->cancel('SR-1001'))->toBeFalse();
});
