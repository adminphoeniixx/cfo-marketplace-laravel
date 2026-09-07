<?php

use App\Models\DeliveryPartner;
use App\Models\Order;
use App\Models\Vendor;
use Illuminate\Support\Facades\Http;

/*
| The courier telling us, rather than us asking.
|
| The fifteen-minute sync is a good floor and a bad ceiling: a shopper watching
| a parcel out for delivery is reading a screen up to a quarter of an hour
| behind, and a failed delivery attempt sits unseen for the same.
|
| Two properties are worth more than the speed, and both are tested here. The
| endpoint is useless to anybody without the shared secret — it is the only
| unauthenticated route that writes to orders — and news arriving twice, once
| by push and once by the next sync, writes nothing the second time.
*/

beforeEach(function () {
    config([
        'services.shiprocket.webhook_token' => 'a-secret-we-invented',
        'services.delhivery.webhook_token' => 'another-one',
    ]);

    DeliveryPartner::where('name', 'Shiprocket')->update(['driver' => 'shiprocket', 'is_active' => true])
        ?: DeliveryPartner::create(['name' => 'Shiprocket', 'code' => 'shiprocket', 'driver' => 'shiprocket', 'is_active' => true]);

    DeliveryPartner::where('name', 'Delhivery')->update(['driver' => 'delhivery', 'is_active' => true]);

    $this->store = Vendor::factory()->create(['status' => 'approved']);
    adminUser();
});

/** An order in flight with the named courier. */
function pushedParcel(string $carrier, string $waybill, array $attributes = []): Order
{
    $order = Order::factory()->create([
        'status' => 'shipped',
        'payment_status' => 'paid',
        'carrier' => $carrier,
        'tracking_number' => $waybill,
        'shipped_at' => now()->subDay(),
        ...$attributes,
    ]);

    $order->items()->create([
        'vendor_id' => test()->store->id, 'name' => 'Kanchipuram silk saree',
        'sku' => 'SKU-'.$order->id, 'unit_price' => 1000, 'quantity' => 1, 'total' => 1000,
    ]);

    return $order;
}

test('a Shiprocket push moves the parcel within seconds', function () {
    $order = pushedParcel('Shiprocket', 'SR-1001');

    $this->postJson(route('api.webhooks.couriers.shiprocket'), [
        'awb' => 'SR-1001',
        'current_status' => 'OUT FOR DELIVERY',
        'scans' => [[
            'sr-status-label' => 'Out for delivery',
            'activity' => 'Assigned to a rider',
            'location' => 'Chennai',
            'date' => '2026-09-06 09:15:00',
        ]],
    ], ['x-api-key' => 'a-secret-we-invented'])->assertOk();

    expect($order->fresh()->shipment_status)->toBe('out_for_delivery')
        ->and($order->events()->where('type', 'shipment')->first()->title)->toBe('Out for delivery');
});

test('a delivery pushed by the courier settles a cash order', function () {
    $order = pushedParcel('Shiprocket', 'SR-1001', [
        'payment_status' => 'pending', 'payment_method' => 'Cash on Delivery',
    ]);

    $this->postJson(route('api.webhooks.couriers.shiprocket'), [
        'awb' => 'SR-1001', 'current_status' => 'DELIVERED', 'scans' => [],
    ], ['x-api-key' => 'a-secret-we-invented'])->assertOk();

    $order->refresh();

    expect($order->status)->toBe('completed')
        ->and($order->delivered_at)->not->toBeNull()
        ->and($order->payment_status)->toBe('paid');
});

test('the same news pushed twice writes one line, not two', function () {
    $order = pushedParcel('Shiprocket', 'SR-1001');

    $push = fn () => test()->postJson(route('api.webhooks.couriers.shiprocket'), [
        'awb' => 'SR-1001',
        'current_status' => 'IN TRANSIT',
        'scans' => [[
            'sr-status-label' => 'In transit', 'activity' => 'Left the hub',
            'location' => 'Chennai', 'date' => '2026-09-06 09:15:00',
        ]],
    ], ['x-api-key' => 'a-secret-we-invented']);

    $push()->assertOk();
    $push()->assertOk();
    $push()->assertOk();

    expect($order->events()->where('type', 'shipment')->count())->toBe(1);
});

test('a push and the sync afterwards do not contradict each other', function () {
    Http::fake(['*' => Http::response(['tracking_data' => ['shipment_track' => [[
        'current_status' => 'IN TRANSIT', 'destination' => 'Chennai',
        'updated_time_stamp' => '2026-09-06 09:15:00',
    ]], 'shipment_track_activities' => [[
        'sr-status-label' => 'In transit', 'activity' => 'Left the hub',
        'location' => 'Chennai', 'date' => '2026-09-06 09:15:00',
    ]]]])]);

    // Through the model, not the query builder: `credentials` is cast
    // `encrypted:array`, and a mass update would write plaintext the cast then
    // cannot read back.
    DeliveryPartner::where('name', 'Shiprocket')->first()
        ->forceFill(['credentials' => ['email' => 'ops@example.test', 'password' => 'x']])
        ->save();

    $order = pushedParcel('Shiprocket', 'SR-1001');

    $this->postJson(route('api.webhooks.couriers.shiprocket'), [
        'awb' => 'SR-1001',
        'current_status' => 'IN TRANSIT',
        'scans' => [[
            'sr-status-label' => 'In transit', 'activity' => 'Left the hub',
            'location' => 'Chennai', 'date' => '2026-09-06 09:15:00',
        ]],
    ], ['x-api-key' => 'a-secret-we-invented'])->assertOk();

    $this->artisan('shipments:sync')->assertSuccessful();

    expect($order->events()->where('type', 'shipment')->count())->toBe(1)
        ->and($order->fresh()->shipment_status)->toBe('in_transit');
});

test('Delhivery pushes one shipment at a time, and the return journey with it', function () {
    $order = pushedParcel('Delhivery', 'AWB-2002');

    $this->postJson(route('api.webhooks.couriers.delhivery', ['token' => 'another-one']), [
        'Shipment' => [
            'AWB' => 'AWB-2002',
            'Status' => [
                'Status' => 'Delivered',
                'StatusType' => 'RT',
                'StatusLocation' => 'Noida_Hub',
                'StatusDateTime' => '2026-09-06T09:15:00',
            ],
        ],
    ])->assertOk();

    $order->refresh();

    // "Delivered" under RT means delivered back to the seller. A shopper must
    // never be shown that as good news.
    expect($order->shipment_status)->toBe('returned')
        ->and($order->delivered_at)->toBeNull()
        ->and($order->returned_at)->not->toBeNull();
});

test('a push with no secret is refused and writes nothing', function () {
    $order = pushedParcel('Shiprocket', 'SR-1001');

    $this->postJson(route('api.webhooks.couriers.shiprocket'), [
        'awb' => 'SR-1001', 'current_status' => 'DELIVERED', 'scans' => [],
    ])->assertUnauthorized();

    expect($order->fresh()->delivered_at)->toBeNull();
});

test('a push with the wrong secret is refused', function () {
    pushedParcel('Shiprocket', 'SR-1001');

    $this->postJson(route('api.webhooks.couriers.shiprocket'), [
        'awb' => 'SR-1001', 'current_status' => 'DELIVERED', 'scans' => [],
    ], ['x-api-key' => 'not-the-secret'])->assertUnauthorized();
});

test('with no secret configured the endpoint refuses everybody', function () {
    config(['services.shiprocket.webhook_token' => null]);

    pushedParcel('Shiprocket', 'SR-1001');

    // An open endpoint that writes to orders until somebody remembers to
    // configure it is the kind of default that is discovered rather than
    // noticed.
    $this->postJson(route('api.webhooks.couriers.shiprocket'), [
        'awb' => 'SR-1001', 'current_status' => 'DELIVERED', 'scans' => [],
    ], ['x-api-key' => ''])->assertUnauthorized();
});

test('a waybill we never booked is answered politely and dropped', function () {
    // 200, not 404: a courier that gets an error retries for hours, and there
    // is nothing here to retry.
    $this->postJson(route('api.webhooks.couriers.shiprocket'), [
        'awb' => 'SOMEBODY-ELSES-PARCEL', 'current_status' => 'DELIVERED', 'scans' => [],
    ], ['x-api-key' => 'a-secret-we-invented'])
        ->assertOk()
        ->assertJsonPath('message', 'No such parcel here.');
});

test('a push cannot reopen a cancelled order', function () {
    $order = pushedParcel('Shiprocket', 'SR-1001', ['status' => 'cancelled']);

    $this->postJson(route('api.webhooks.couriers.shiprocket'), [
        'awb' => 'SR-1001', 'current_status' => 'DELIVERED', 'scans' => [],
    ], ['x-api-key' => 'a-secret-we-invented'])->assertOk();

    expect($order->fresh()->status)->toBe('cancelled')
        ->and($order->fresh()->delivered_at)->toBeNull();
});

test('a Delhivery waybill pushed to the Shiprocket endpoint is not ours to take', function () {
    $order = pushedParcel('Delhivery', 'AWB-2002');

    $this->postJson(route('api.webhooks.couriers.shiprocket'), [
        'awb' => 'AWB-2002', 'current_status' => 'DELIVERED', 'scans' => [],
    ], ['x-api-key' => 'a-secret-we-invented'])
        ->assertOk()
        ->assertJsonPath('message', 'No such parcel here.');

    expect($order->fresh()->delivered_at)->toBeNull();
});
