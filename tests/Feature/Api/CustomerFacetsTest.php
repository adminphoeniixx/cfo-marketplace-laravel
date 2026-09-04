<?php

use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Vendor;

/*
| The filter sheet, sent rather than guessed.
|
| The app's own chips were a guess at the catalogue — a price band nothing
| falls into, a seller who has stopped trading, a brand never stocked. These
| tests are about the counts being true of the catalogue as it stands.
*/

test('the filter sheet is counted against the catalogue it is filtering', function () {
    $category = Category::factory()->create();
    $meera = Vendor::factory()->create(['status' => 'approved', 'name' => 'Meera Textiles', 'is_assured' => true]);
    $ravi = Vendor::factory()->create(['status' => 'approved', 'name' => 'Ravi Looms']);

    sellableProduct(['price' => 400, 'brand' => 'Meera Looms', 'category_id' => $category->id], $meera);
    sellableProduct(['price' => 900, 'brand' => 'Meera Looms', 'category_id' => $category->id], $meera);
    sellableProduct(['price' => 6000, 'brand' => 'Ravi Silks', 'category_id' => $category->id], $ravi);
    // Another category entirely: it must not be counted here.
    sellableProduct(['price' => 100, 'brand' => 'Elsewhere'], $ravi);

    $response = $this->getJson(route('api.customer.products.filters', ['category_id' => $category->id]))
        ->assertOk()
        ->assertJsonPath('data.total', 3);

    $bands = collect($response->json('data.price_ranges'))->keyBy('label');

    expect($bands['Under ₹500']['count'])->toBe(1)
        ->and($bands['₹500 – ₹1,000']['count'])->toBe(1)
        ->and($bands['Over ₹5,000']['count'])->toBe(1);

    $sellers = collect($response->json('data.sellers'))->keyBy('name');

    expect($sellers['Meera Textiles']['count'])->toBe(2)
        ->and($sellers['Ravi Looms']['count'])->toBe(1)
        ->and(collect($response->json('data.brands'))->pluck('name')->all())
        ->not->toContain('Elsewhere');

    $booleans = collect($response->json('data.boolean_filters'))->keyBy('key');

    expect($booleans['assured']['count'])->toBe(2);
});

test('a chosen seller does not empty the seller list', function () {
    $meera = Vendor::factory()->create(['status' => 'approved', 'name' => 'Meera Textiles']);
    $ravi = Vendor::factory()->create(['status' => 'approved', 'name' => 'Ravi Looms']);

    sellableProduct([], $meera);
    sellableProduct([], $ravi);

    // Each facet is counted with every filter but its own, or picking one
    // seller would leave a sheet with one seller in it and no way back.
    $response = $this->getJson(route('api.customer.products.filters', ['vendor_id' => $meera->id]))
        ->assertOk()
        // The listing itself narrows, and says so.
        ->assertJsonPath('data.total', 1);

    expect(collect($response->json('data.sellers'))->pluck('name')->all())
        ->toContain('Meera Textiles')
        ->toContain('Ravi Looms');
});

test('assured and cash on delivery actually filter', function () {
    $assured = Vendor::factory()->create(['status' => 'approved', 'is_assured' => true, 'cod_available' => true]);
    $cash = Vendor::factory()->create(['status' => 'approved', 'is_assured' => false, 'cod_available' => false]);

    sellableProduct(['name' => 'Assured saree'], $assured);
    sellableProduct(['name' => 'Card only lamp'], $cash);

    $this->getJson(route('api.customer.products', ['assured' => 1]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Assured saree')
        ->assertJsonPath('data.0.assured', true);

    $this->getJson(route('api.customer.products', ['cod' => 1]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Assured saree')
        ->assertJsonPath('data.0.cod_available', true);
});

test('with cash switched off marketplace-wide, the chip matches nothing', function () {
    $vendor = Vendor::factory()->create(['status' => 'approved', 'cod_available' => true]);
    sellableProduct([], $vendor);

    PaymentMethod::query()->update(['is_active' => false]);

    $this->getJson(route('api.customer.products', ['cod' => 1]))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('an unknown sort is refused rather than quietly ignored', function () {
    $this->getJson(route('api.customer.products', ['sort' => 'cheapest']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('sort');
});

test('the listing carries the pagination metadata the grid pages on', function () {
    sellableProduct();

    $this->getJson(route('api.customer.products', ['per_page' => 1]))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('per_page', 1)
        ->assertJsonPath('current_page', 1)
        ->assertJsonPath('last_page', 1);
});
