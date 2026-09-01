<?php

use App\Models\Category;
use App\Models\ProductReview;
use App\Models\Vendor;

test('browsing works without a token', function () {
    sellableProduct(['name' => 'Kanchipuram silk saree']);

    $this->getJson(route('api.customer.products'))
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Kanchipuram silk saree')
        // Nobody is signed in, so the wishlist flag is absent rather than false.
        ->assertJsonMissingPath('data.0.is_wishlisted');
});

test('only active, published products are on sale', function () {
    sellableProduct(['name' => 'On sale']);
    sellableProduct(['name' => 'Still a draft', 'status' => 'draft']);
    sellableProduct(['name' => 'Archived', 'status' => 'archived']);
    sellableProduct(['name' => 'Scheduled', 'published_at' => now()->addWeek()]);

    $names = collect($this->getJson(route('api.customer.products'))->json('data'))->pluck('name');

    expect($names)->toContain('On sale')
        ->not->toContain('Still a draft')
        ->not->toContain('Archived')
        ->not->toContain('Scheduled');
});

test('a draft product 404s even when asked for by id', function () {
    $draft = sellableProduct(['status' => 'draft']);

    $this->getJson(route('api.customer.products.show', $draft->id))->assertNotFound();
});

test('a category means the category and everything under it', function () {
    $parent = Category::factory()->create(['name' => 'Ethnic wear', 'is_active' => true]);
    $child = Category::factory()->create(['parent_id' => $parent->id, 'is_active' => true]);

    sellableProduct(['name' => 'Filed under the parent', 'category_id' => $parent->id]);
    sellableProduct(['name' => 'Filed under the child', 'category_id' => $child->id]);
    sellableProduct(['name' => 'Somewhere else']);

    $names = collect($this->getJson(route('api.customer.products', ['category_id' => $parent->id]))->json('data'))
        ->pluck('name');

    expect($names)->toHaveCount(2)
        ->toContain('Filed under the parent')
        ->toContain('Filed under the child');
});

test('the listing sorts and filters the way the chips do', function () {
    sellableProduct(['name' => 'Cheap', 'price' => 100, 'compare_at_price' => 110, 'rating' => 3]);
    sellableProduct(['name' => 'Dear', 'price' => 5000, 'compare_at_price' => 10000, 'rating' => 5]);

    expect($this->getJson(route('api.customer.products', ['sort' => 'price_low']))->json('data.0.name'))->toBe('Cheap');
    expect($this->getJson(route('api.customer.products', ['sort' => 'price_high']))->json('data.0.name'))->toBe('Dear');
    expect($this->getJson(route('api.customer.products', ['sort' => 'rating']))->json('data.0.name'))->toBe('Dear');

    $filtered = $this->getJson(route('api.customer.products', ['min_discount' => 40]))->json('data');
    expect($filtered)->toHaveCount(1)
        ->and($filtered[0]['name'])->toBe('Dear')
        ->and($filtered[0]['discount_percent'])->toBe(50);

    $cheapOnly = $this->getJson(route('api.customer.products', ['max_price' => 1000]))->json('data');
    expect($cheapOnly)->toHaveCount(1)->and($cheapOnly[0]['name'])->toBe('Cheap');
});

test('search matches names and brands', function () {
    sellableProduct(['name' => 'Linen dupatta', 'brand' => 'Meera Looms']);
    sellableProduct(['name' => 'Canvas sneakers', 'brand' => 'Bombay Kicks']);

    expect(collect($this->getJson(route('api.customer.products', ['q' => 'linen']))->json('data'))->pluck('name'))
        ->toContain('Linen dupatta')->toHaveCount(1);

    expect(collect($this->getJson(route('api.customer.products', ['q' => 'bombay']))->json('data'))->pluck('name'))
        ->toContain('Canvas sneakers')->toHaveCount(1);
});

test('search does not care about case', function () {
    // Caught on the live server: `like` is case-sensitive on Postgres and not
    // on SQLite, so a lowercase query found nothing in production while the
    // tests stayed green.
    sellableProduct(['name' => 'Vitamin C Brightening Serum', 'brand' => 'Glow Lab']);

    foreach (['serum', 'SERUM', 'Serum', 'brightening'] as $term) {
        expect($this->getJson(route('api.customer.products', ['q' => $term]))->json('data'))
            ->toHaveCount(1, "searching for {$term}");
    }

    expect($this->getJson(route('api.customer.products', ['q' => 'glow lab']))->json('data'))->toHaveCount(1);

    expect($this->getJson(route('api.customer.products.suggestions', ['q' => 'vitamin']))->json('products'))
        ->toHaveCount(1);
});

test('the rating histogram carries every star, including the empty ones', function () {
    $product = sellableProduct();
    ProductReview::factory()->count(2)->create(['product_id' => $product->id, 'rating' => 5]);
    ProductReview::factory()->create(['product_id' => $product->id, 'rating' => 3]);

    $breakdown = $this->getJson(route('api.customer.products.show', $product->id))
        ->assertOk()
        ->json('data.rating_breakdown');

    // Five buckets, highest first, each an object — a rating-keyed map does not
    // survive the resource serializer, and the app needs the zeroes to draw
    // the bars.
    expect($breakdown)->toHaveCount(5)
        ->and($breakdown[0])->toBe(['rating' => 5, 'count' => 2])
        ->and($breakdown[2])->toBe(['rating' => 3, 'count' => 1])
        ->and($breakdown[4])->toBe(['rating' => 1, 'count' => 0]);
});

test('the product page carries the seller, the pickers and the specs', function () {
    $vendor = Vendor::factory()->create(['status' => 'approved', 'name' => 'Meera Textiles', 'city' => 'Kanchipuram']);
    $product = sellableProduct(['name' => 'Cotton kurta', 'brand' => 'Meera Looms'], $vendor);

    $this->getJson(route('api.customer.products.show', $product->slug))
        ->assertOk()
        ->assertJsonPath('data.name', 'Cotton kurta')
        ->assertJsonPath('data.vendor.name', 'Meera Textiles')
        ->assertJsonPath('data.vendor.city', 'Kanchipuram')
        ->assertJsonPath('data.specifications.Brand', 'Meera Looms')
        ->assertJsonStructure(['data' => ['options', 'variants', 'rating_breakdown', 'in_stock']]);
});

test('a signed-in shopper sees what they have saved', function () {
    $customer = actingAsCustomer();
    $product = sellableProduct();
    $customer->wishlistItems()->create(['product_id' => $product->id]);

    $this->getJson(route('api.customer.products'))
        ->assertOk()
        ->assertJsonPath('data.0.is_wishlisted', true);
});

test('the home screen paints in one call', function () {
    sellableProduct(['price' => 500, 'compare_at_price' => 1000]);

    $this->getJson(route('api.customer.home'))
        ->assertOk()
        ->assertJsonStructure(['categories', 'deals', 'top_rated', 'new_arrivals', 'sellers']);
});

test('a storefront lists only that seller, and only approved ones exist', function () {
    $vendor = Vendor::factory()->create(['status' => 'approved']);
    $other = Vendor::factory()->create(['status' => 'approved']);
    $pending = Vendor::factory()->create(['status' => 'pending']);

    sellableProduct(['name' => 'Theirs'], $vendor);
    sellableProduct(['name' => 'Not theirs'], $other);

    $response = $this->getJson(route('api.customer.sellers.show', $vendor->id))->assertOk();

    expect(collect($response->json('products.data'))->pluck('name'))->toContain('Theirs')->toHaveCount(1);

    $this->getJson(route('api.customer.sellers.show', $pending->id))->assertNotFound();
});

test('suggestions stay quiet until there is something to go on', function () {
    sellableProduct(['name' => 'Wool shawl']);

    $this->getJson(route('api.customer.products.suggestions', ['q' => 'w']))
        ->assertOk()
        ->assertJsonPath('products', []);

    $this->getJson(route('api.customer.products.suggestions', ['q' => 'wool']))
        ->assertOk()
        ->assertJsonPath('products.0.name', 'Wool shawl');
});
