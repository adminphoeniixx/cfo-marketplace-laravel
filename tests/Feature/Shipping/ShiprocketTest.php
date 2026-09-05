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
