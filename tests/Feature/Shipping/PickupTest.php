<?php

use App\Models\DeliveryPartner;
use App\Models\Order;
use App\Models\Vendor;
use Illuminate\Support\Facades\Http;

/*
| Asking a courier to actually come and collect.
|
| Booking a waybill is not a pickup, and that gap is quiet: both providers will
| take a booking, print a label and then wait to be asked separately for a van.
| Without this a marketplace can have a week of parcels manifested, labelled,
| and sitting on a table.
|
| One request per courier per day is the shape both APIs want — and the shape
| they bill for — so the tests below care as much about how many calls are made
| as about what they say.
*/

beforeEach(function () {
    config([
        'services.delhivery.token' => 'test-token',
        'services.delhivery.base_url' => 'https://staging-express.delhivery.com',
        'services.delhivery.pickup_name' => 'CFO Noida',
    ]);

    DeliveryPartner::where('name', 'Delhivery')->update(['driver' => 'delhivery', 'is_active' => true]);

    $this->store = Vendor::factory()->create(['status' => 'approved']);
});

/** A parcel booked and waiting on the packing table. */
function waitingParcel(string $waybill, array $attributes = []): Order
{
    $order = Order::factory()->create([
        'status' => 'processing',
        'carrier' => 'Delhivery',
        'tracking_number' => $waybill,
        'shipped_at' => null,
        ...$attributes,
    ]);

    $order->items()->create([
        'vendor_id' => test()->store->id, 'name' => 'Kanchipuram silk saree',
        'sku' => 'SKU-'.$order->id, 'unit_price' => 1000, 'quantity' => 1, 'total' => 1000,
    ]);

    return $order;
}

test('a morning’s parcels are collected in one request', function () {
    Http::fake(['*/fm/request/new/' => Http::response(['pickup_id' => 'PU-9001'])]);

    $one = waitingParcel('AWB-1');
    $two = waitingParcel('AWB-2');
    $three = waitingParcel('AWB-3');

    $this->artisan('shipments:pickup')->assertSuccessful();

    Http::assertSentCount(1);

    // Delhivery books against a warehouse and a count, so that is what it is told.
    Http::assertSent(fn ($request) => $request['expected_package_count'] === 3
        && $request['pickup_location'] === 'CFO Noida');

    foreach ([$one, $two, $three] as $order) {
        expect($order->fresh()->pickup_scheduled_at)->not->toBeNull();
    }

    expect($one->fresh()->events()->where('type', 'shipment')->first()->meta['pickup_reference'])
        ->toBe('PU-9001');
});

test('a second run does not book — or pay for — the same collection twice', function () {
    Http::fake(['*/fm/request/new/' => Http::response(['pickup_id' => 'PU-9001'])]);

    waitingParcel('AWB-1');

    $this->artisan('shipments:pickup')->assertSuccessful();
    $this->artisan('shipments:pickup')->assertSuccessful();

    Http::assertSentCount(1);
});

test('parcels already on their way are not collected again', function () {
    Http::fake(['*/fm/request/new/' => Http::response(['pickup_id' => 'PU-9001'])]);

    waitingParcel('AWB-1', ['shipped_at' => now(), 'shipment_status' => 'in_transit']);
    waitingParcel('AWB-2', ['status' => 'cancelled']);

    $this->artisan('shipments:pickup')->assertSuccessful();

    Http::assertNothingSent();
});

test('a refused collection is reported and leaves the parcels unbooked', function () {
    Http::fake(['*/fm/request/new/' => Http::response(['error' => 'A pickup already exists for this slot.'], 400)]);

    $order = waitingParcel('AWB-1');

    $this->artisan('shipments:pickup')
        ->expectsOutputToContain('A pickup already exists for this slot.')
        ->assertSuccessful();

    // Unbooked, so tomorrow's run tries again rather than the parcel sitting
    // for ever behind a timestamp that was never true.
    expect($order->fresh()->pickup_scheduled_at)->toBeNull();
});

test('with no courier connected nothing is asked of anybody', function () {
    DeliveryPartner::query()->update(['driver' => null]);
    Http::fake();

    waitingParcel('AWB-1');

    $this->artisan('shipments:pickup')
        ->expectsOutputToContain('No courier is connected')
        ->assertSuccessful();

    Http::assertNothingSent();
});

test('the panel button books the same collection as the morning run', function () {
    Http::fake(['*/fm/request/new/' => Http::response(['pickup_id' => 'PU-9001'])]);

    $order = waitingParcel('AWB-1');
    $partner = DeliveryPartner::where('name', 'Delhivery')->firstOrFail();

    $this->actingAs(actingAsAdmin())
        ->post(route('admin.delivery-partners.pickup', $partner->id))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($order->fresh()->pickup_scheduled_at)->not->toBeNull();
});

test('the panel button says so when there is nothing waiting', function () {
    Http::fake();

    $partner = DeliveryPartner::where('name', 'Delhivery')->firstOrFail();

    $this->actingAs(actingAsAdmin())
        ->post(route('admin.delivery-partners.pickup', $partner->id))
        ->assertSessionHas('error');

    Http::assertNothingSent();
});
