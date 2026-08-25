<?php

use App\Models\Vendor;

/*
|--------------------------------------------------------------------------
| Reports
|--------------------------------------------------------------------------
|
| `/analytics/report` is the marketplace's own report controller, subclassed.
| These tests pin the two things that subclassing could get wrong: that every
| number is narrowed to the caller's store, and that the marketplace-only
| views do not come along with it.
|
*/

test('the report answers for this store alone', function () {
    [, $store] = actingAsSeller();

    orderForStore($store)->update(['placed_at' => now()->subDay()]);
    orderForStore(Vendor::factory()->create())->update(['placed_at' => now()->subDay()]);

    $response = $this->getJson(route('api.seller.analytics.report'))->assertOk();

    expect($response->json('metrics.gross_sales.value'))->toEqual(2000)
        ->and($response->json('metrics.orders.value'))->toBe(1)
        ->and($response->json('metrics.units.value'))->toBe(2)
        ->and($response->json('metrics.vendor_earnings.value'))->toEqual(1800)
        ->and($response->json())->toHaveKeys([
            'window', 'series', 'by_category', 'top_products',
            'top_customers', 'by_payment_method', 'status_breakdown',
            'payment_breakdown', 'fulfillment_breakdown', 'exports',
        ])
        // The marketplace leaderboard is neither returned nor exportable.
        ->and($response->json())->not->toHaveKey('by_vendor')
        ->and($response->json('exports'))->not->toContain('vendors');
});

test('the window follows the same preset and from/to the panel uses', function () {
    [, $store] = actingAsSeller();

    orderForStore($store)->update(['placed_at' => now()->subDays(40)]);

    $this->getJson(route('api.seller.analytics.report', ['preset' => 7]))
        ->assertOk()
        ->assertJsonPath('window.preset', 7)
        ->assertJsonPath('window.from', now()->subDays(6)->toDateString())
        // Outside the window, so it counts for nothing.
        ->assertJsonPath('metrics.orders.value', 0);

    $this->getJson(route('api.seller.analytics.report', [
        'from' => now()->subDays(45)->toDateString(),
        'to' => now()->toDateString(),
    ]))
        ->assertOk()
        ->assertJsonPath('window.preset', null)
        ->assertJsonPath('metrics.orders.value', 1);
});

test('a report exports as CSV holding only this store\'s rows', function () {
    [, $store] = actingAsSeller();

    $mine = orderForStore($store);
    $mine->update(['placed_at' => now()->subDay()]);

    $theirs = orderForStore(Vendor::factory()->create());
    $theirs->update(['placed_at' => now()->subDay()]);

    $response = $this->get(route('api.seller.analytics.export', 'orders'))->assertOk();

    $csv = $response->streamedContent();

    expect($csv)->toContain($mine->number)
        ->and($csv)->not->toContain($theirs->number);
});

test('the vendor leaderboard is not a report a seller can pull', function () {
    actingAsSeller();

    $this->get(route('api.seller.analytics.export', 'vendors'))->assertNotFound();
    $this->get(route('api.seller.analytics.export', 'nonsense'))->assertNotFound();
    $this->get(route('api.seller.analytics.export', 'products'))->assertOk();
});
