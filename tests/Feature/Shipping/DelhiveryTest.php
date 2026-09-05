<?php

use App\Models\DeliveryPartner;
use App\Models\Order;
use App\Models\Vendor;
use App\Services\Couriers\Couriers;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
| Booking a parcel and asking where it got to.
|
| Every test here runs with a token configured, because that is the whole
| point: with none the marketplace keeps its old behaviour of the seller typing
| a waybill in by hand, and the first test below is the one that proves it.
*/

beforeEach(function () {
    config([
        'services.delhivery.token' => 'test-token',
        'services.delhivery.base_url' => 'https://staging-express.delhivery.com',
        'services.delhivery.pickup_name' => 'CFO Noida',
        'services.delhivery.seller_name' => 'CFO',
    ]);

    // A courier row with a driver is what makes an order bookable now; the
    // credentials still fall back to the environment above.
    DeliveryPartner::where('name', 'Delhivery')->update(['driver' => 'delhivery']);
});

/** An order of this store's, ready to be handed over. */
function shippableOrder(Vendor $vendor, array $attributes = []): Order
{
    $order = Order::factory()->create([
        'status' => 'processing',
        'payment_status' => 'paid',
        'grand_total' => 1180,
        'shipping_address' => [
            'first_name' => 'Priya', 'last_name' => 'Nair',
            'address_line1' => '42 Beach Road', 'city' => 'Chennai',
            'state' => 'Tamil Nadu', 'postcode' => '600090', 'phone' => '9876543210',
        ],
        ...$attributes,
    ]);

    $order->items()->create([
        'vendor_id' => $vendor->id,
        'name' => 'Kanchipuram silk saree',
        'sku' => 'SKU-'.$order->id,
        'unit_price' => 1000, 'quantity' => 1, 'total' => 1000,
    ]);

    return $order->load('items');
}

test('with no token nothing is booked and the seller types the waybill in', function () {
    config(['services.delhivery.token' => null]);
    Http::fake();

    [, $store] = actingAsSeller();
    $order = shippableOrder($store);

    $this->postJson(route('api.seller.orders.fulfill', $order->id), [
        'items' => [['id' => $order->items->first()->id, 'quantity' => 1]],
        'carrier' => 'delhivery',
        'tracking_number' => 'TYPED-BY-HAND',
    ])->assertOk();

    Http::assertNothingSent();
    expect($order->fresh()->tracking_number)->toBe('TYPED-BY-HAND');
});

test('fulfilling books a waybill and puts it on the order', function () {
    Http::fake([
        'staging-express.delhivery.com/api/cmu/create.json' => Http::response([
            'success' => true,
            'packages' => [['waybill' => 'DL1234567890', 'status' => 'Success']],
        ]),
    ]);

    [, $store] = actingAsSeller();
    $order = shippableOrder($store);

    $this->postJson(route('api.seller.orders.fulfill', $order->id), [
        'items' => [['id' => $order->items->first()->id, 'quantity' => 1]],
        'carrier' => 'delhivery',
    ])->assertOk();

    expect($order->fresh()->tracking_number)->toBe('DL1234567890')
        ->and($order->fresh()->carrier)->toBe('delhivery')
        // On the timeline, so the shopper's order page says it too.
        ->and($order->events()->where('type', 'shipment')->first()->title)
        ->toBe('Booked with delhivery');

    Http::assertSent(function ($request) {
        // Their own shape: a form body whose `data` field is JSON. Sending it
        // as JSON returns a cheerful 200 with no waybill.
        $data = json_decode($request['data'], true);

        return $request->hasHeader('Authorization', 'Token test-token')
            && $data['pickup_location']['name'] === 'CFO Noida'
            && $data['shipments'][0]['order'] === Order::first()->number
            && $data['shipments'][0]['payment_mode'] === 'Prepaid'
            && $data['shipments'][0]['cod_amount'] === 0;
    });
});

test('a cash-on-delivery order is booked as COD, for the right amount', function () {
    Http::fake([
        'staging-express.delhivery.com/*' => Http::response([
            'packages' => [['waybill' => 'DL9999999999']],
        ]),
    ]);

    [, $store] = actingAsSeller();
    $order = shippableOrder($store, [
        'payment_method' => 'Cash on Delivery',
        'payment_status' => 'pending',
    ]);

    $this->postJson(route('api.seller.orders.fulfill', $order->id), [
        'items' => [['id' => $order->items->first()->id, 'quantity' => 1]],
        'carrier' => 'delhivery',
    ])->assertOk();

    Http::assertSent(function ($request) {
        $shipment = json_decode($request['data'], true)['shipments'][0];

        // `json_encode` writes 1180.0 as `1180`, so it decodes as an int.
        return $shipment['payment_mode'] === 'COD' && (float) $shipment['cod_amount'] === 1180.0;
    });
});

test('a refused booking is recorded and the fulfilment still stands', function () {
    // Delhivery answers 200 with no waybill on a refusal, so the status code
    // is not the test.
    Http::fake([
        'staging-express.delhivery.com/*' => Http::response([
            'success' => false,
            'packages' => [['remarks' => ['ClientWarehouse matching query does not exist.']]],
        ]),
    ]);

    [, $store] = actingAsSeller();
    $order = shippableOrder($store);

    $this->postJson(route('api.seller.orders.fulfill', $order->id), [
        'items' => [['id' => $order->items->first()->id, 'quantity' => 1]],
        'carrier' => 'delhivery',
    ])->assertOk();

    $fresh = $order->fresh();

    expect($fresh->tracking_number)->toBeNull()
        // The parcel exists whatever the courier said, so the seller's work is
        // not undone — and the reason is on the order where somebody can read it.
        ->and($fresh->fulfillment_status)->toBe('fulfilled')
        ->and($fresh->events()->where('type', 'shipment')->first()->body)
        ->toBe('ClientWarehouse matching query does not exist.');
});

test('a waybill already on the order is never booked over', function () {
    Http::fake();

    [, $store] = actingAsSeller();
    $order = shippableOrder($store, ['carrier' => 'delhivery', 'tracking_number' => 'DL0000000001']);

    $this->postJson(route('api.seller.orders.fulfill', $order->id), [
        'items' => [['id' => $order->items->first()->id, 'quantity' => 1]],
        'carrier' => 'delhivery',
    ])->assertOk();

    // Two labels on one box is worse than none.
    Http::assertNothingSent();
    expect($order->fresh()->tracking_number)->toBe('DL0000000001');
});

test('the sync writes the courier’s scans onto the order and closes it on delivery', function () {
    Http::fake([
        'staging-express.delhivery.com/api/v1/packages/json*' => Http::response([
            'ShipmentData' => [['Shipment' => [
                'Status' => [
                    'Status' => 'Delivered',
                    'StatusLocation' => 'Chennai',
                    'StatusDateTime' => '2026-09-04T10:00:00',
                ],
                'Scans' => [
                    ['ScanDetail' => [
                        'Scan' => 'In Transit', 'Instructions' => 'Shipment picked up',
                        'ScannedLocation' => 'Noida', 'ScanDateTime' => '2026-09-02T09:00:00',
                    ]],
                    ['ScanDetail' => [
                        'Scan' => 'Delivered', 'Instructions' => 'Delivered to consignee',
                        'ScannedLocation' => 'Chennai', 'ScanDateTime' => '2026-09-04T10:00:00',
                    ]],
                ],
            ]]],
        ]),
    ]);

    $vendor = Vendor::factory()->create(['status' => 'approved']);
    $order = shippableOrder($vendor, [
        'carrier' => 'delhivery',
        'tracking_number' => 'DL1234567890',
        'payment_method' => 'Cash on Delivery',
        'payment_status' => 'pending',
    ]);

    $this->artisan('shipments:sync')->assertSuccessful();

    $fresh = $order->fresh();

    expect($fresh->delivered_at)->not->toBeNull()
        ->and($fresh->status)->toBe('completed')
        ->and($fresh->shipped_at)->not->toBeNull()
        // Cash collected at the door is the moment a COD order is paid, and
        // nothing else was ever going to tell the marketplace.
        ->and($fresh->payment_status)->toBe('paid')
        ->and($fresh->events()->where('type', 'shipment')->count())->toBe(2);
});

test('a second run does not write the same scan twice', function () {
    Http::fake([
        'staging-express.delhivery.com/api/v1/packages/json*' => Http::response([
            'ShipmentData' => [['Shipment' => [
                'Status' => ['Status' => 'In Transit'],
                'Scans' => [['ScanDetail' => [
                    'Scan' => 'In Transit', 'ScannedLocation' => 'Noida',
                    'ScanDateTime' => '2026-09-02T09:00:00',
                ]]],
            ]]],
        ]),
    ]);

    $vendor = Vendor::factory()->create(['status' => 'approved']);
    $order = shippableOrder($vendor, ['carrier' => 'delhivery', 'tracking_number' => 'DL1234567890']);

    $this->artisan('shipments:sync')->assertSuccessful();
    $this->artisan('shipments:sync')->assertSuccessful();

    // A run every fifteen minutes would otherwise put the same line on the
    // timeline four times an hour.
    expect($order->fresh()->events()->where('type', 'shipment')->count())->toBe(1);
});

test('an unreachable courier changes nothing', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    $vendor = Vendor::factory()->create(['status' => 'approved']);
    $order = shippableOrder($vendor, ['carrier' => 'delhivery', 'tracking_number' => 'DL1234567890']);

    $this->artisan('shipments:sync')->assertSuccessful();

    // "We could not ask" is not "nothing happened", and must never be written
    // down as if it were.
    expect($order->fresh()->delivered_at)->toBeNull()
        ->and($order->fresh()->events()->where('type', 'shipment')->count())->toBe(0);
});

test('serviceability answers what a pincode will take', function () {
    Http::fake([
        'staging-express.delhivery.com/c/api/pin-codes/json*' => Http::response([
            'delivery_codes' => [['postal_code' => [
                'pin' => 600090, 'cod' => 'Y', 'pre_paid' => 'Y', 'pickup' => 'N',
            ]]],
        ]),
    ]);

    $client = Couriers::forCarrier('Delhivery');

    expect($client?->serviceability('600090'))
        ->toBe(['serviceable' => true, 'cod' => true, 'prepaid' => true, 'pickup' => false]);
});
