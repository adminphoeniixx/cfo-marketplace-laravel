<?php

use App\Actions\Shipping\FetchLabel;
use App\Models\DeliveryPartner;
use App\Models\Order;
use App\Models\Vendor;
use Illuminate\Support\Facades\Http;

/*
| The thing that actually goes on the box.
|
| A marketplace that books a waybill and then sends the seller to the courier's
| own panel to print a label has integrated the courier for its own benefit and
| not for theirs. This is the other half.
|
| The URL is stored on the order because both providers charge a call for it,
| and a packing table reprinting a smudged label all morning should not cost a
| call each time.
*/

beforeEach(function () {
    config([
        'services.delhivery.token' => 'test-token',
        'services.delhivery.base_url' => 'https://staging-express.delhivery.com',
        'services.delhivery.pickup_name' => 'CFO Noida',
    ]);

    DeliveryPartner::where('name', 'Delhivery')->update(['driver' => 'delhivery', 'is_active' => true]);
});

/** A parcel already booked with Delhivery, belonging to this store. */
function bookedParcel(Vendor $vendor, array $attributes = []): Order
{
    $order = Order::factory()->create([
        'status' => 'shipped',
        'carrier' => 'Delhivery',
        'tracking_number' => 'AWB-4411',
        ...$attributes,
    ]);

    $order->items()->create([
        'vendor_id' => $vendor->id, 'name' => 'Kanchipuram silk saree',
        'sku' => 'SKU-'.$order->id, 'unit_price' => 1000, 'quantity' => 1, 'total' => 1000,
    ]);

    return $order->load('items');
}

test('the label is fetched once and remembered on the order', function () {
    Http::fake(['*/api/p/packing_slip*' => Http::response([
        'packages' => [['pdf_download_link' => 'https://delhivery.test/slips/AWB-4411.pdf']],
    ])]);

    [, $store] = actingAsSeller();
    $order = bookedParcel($store);

    $labels = app(FetchLabel::class);

    expect($labels->handle($order))->toBe('https://delhivery.test/slips/AWB-4411.pdf')
        ->and($order->fresh()->shipment_label_url)->toBe('https://delhivery.test/slips/AWB-4411.pdf');

    // Reprinting is free.
    $labels->handle($order->fresh());
    $labels->handle($order->fresh());

    Http::assertSentCount(1);
});

test('refresh asks again, for a link the courier has expired', function () {
    Http::fake(['*/api/p/packing_slip*' => Http::response([
        'packages' => [['pdf_download_link' => 'https://delhivery.test/slips/new.pdf']],
    ])]);

    [, $store] = actingAsSeller();
    $order = bookedParcel($store, ['shipment_label_url' => 'https://delhivery.test/slips/old.pdf']);

    expect(app(FetchLabel::class)->handle($order, refresh: true))
        ->toBe('https://delhivery.test/slips/new.pdf');

    Http::assertSentCount(1);
});

test('the seller API hands back a URL rather than a PDF', function () {
    Http::fake(['*/api/p/packing_slip*' => Http::response([
        'packages' => [['pdf_download_link' => 'https://delhivery.test/slips/AWB-4411.pdf']],
    ])]);

    [, $store] = actingAsSeller();
    $order = bookedParcel($store);

    $this->getJson(route('api.seller.orders.label', $order->id))
        ->assertOk()
        ->assertJsonPath('url', 'https://delhivery.test/slips/AWB-4411.pdf')
        ->assertJsonPath('tracking_number', 'AWB-4411');
});

test('a parcel the courier has not manifested yet says so instead of failing', function () {
    // Delhivery answers a slip request for an unmanifested waybill with a
    // perfectly successful empty package list.
    Http::fake(['*/api/p/packing_slip*' => Http::response(['packages' => []])]);

    [, $store] = actingAsSeller();
    $order = bookedParcel($store);

    $this->getJson(route('api.seller.orders.label', $order->id))
        ->assertStatus(409)
        ->assertJsonPath('url', null)
        ->assertJsonPath('message', 'The courier has no label for this parcel yet. Try again in a minute.');
});

test('an order with no booking has nothing to print and troubles no courier', function () {
    Http::fake();

    [, $store] = actingAsSeller();
    $order = bookedParcel($store, ['tracking_number' => null, 'carrier' => null]);

    $this->getJson(route('api.seller.orders.label', $order->id))
        ->assertStatus(409)
        ->assertJsonPath('message', 'This order has no booking to print a label for.');

    Http::assertNothingSent();
});

test('a seller cannot print the label for somebody else’s parcel', function () {
    Http::fake();

    actingAsSeller();
    $other = bookedParcel(Vendor::factory()->create(['status' => 'approved']));

    $this->getJson(route('api.seller.orders.label', $other->id))->assertNotFound();

    Http::assertNothingSent();
});

test('the panel sends the seller straight to the courier’s PDF', function () {
    Http::fake(['*/api/p/packing_slip*' => Http::response([
        'packages' => [['pdf_download_link' => 'https://delhivery.test/slips/AWB-4411.pdf']],
    ])]);

    [$seller, $store] = actingAsSeller();
    $order = bookedParcel($store);

    $this->actingAs($seller)
        ->get(route('seller.orders.label', $order->id))
        ->assertRedirect('https://delhivery.test/slips/AWB-4411.pdf');
});
