<?php

use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Roles;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The seller panel is the web half of the seller app, and its whole reason for
 * living on its own prefix is scoping: unlike the admin catalog screens, every
 * query here starts from the signed-in user's own store.
 *
 * These tests hold that line from both sides — what a seller may reach, and
 * what stays a 404 no matter how the id is supplied.
 */
function sellerOnPanel(array $vendorAttributes = []): array
{
    $vendor = Vendor::factory()->create([
        'status' => 'approved',
        ...$vendorAttributes,
    ]);

    $user = User::factory()->create([
        'role' => 'vendor',
        'vendor_id' => $vendor->id,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    test()->actingAs($user);

    return [$user, $vendor];
}

test('a seller lands on their own catalog and sees only their own products', function () {
    [, $store] = sellerOnPanel();

    $mine = Product::factory()->count(2)->create(['vendor_id' => $store->id]);
    $theirs = Product::factory()->create([
        'vendor_id' => Vendor::factory()->create(['status' => 'approved'])->id,
    ]);

    $this->get('/seller')->assertRedirect('/seller/orders');

    $this->get(route('seller.products.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('seller/products/Index')
            ->where('counts.all', 2)
            ->has('products.data', 2)
            ->where('store.id', $store->id)
        );

    $listed = collect(
        $this->get(route('seller.products.index'))->viewData('page')['props']['products']['data']
    )->pluck('id');

    expect($listed)->toContain(...$mine->pluck('id')->all())
        ->and($listed)->not->toContain($theirs->id);
});

test('the store is taken from the session, never from the payload', function () {
    [, $store] = sellerOnPanel();
    $other = Vendor::factory()->create(['status' => 'approved']);

    $this->post(route('seller.products.store'), [
        'name' => 'Hand-loom saree',
        'type' => 'simple',
        'price' => 2499,
        'status' => 'draft',
        'track_inventory' => true,
        'stock_quantity' => 5,
        // A seller must not be able to file a product under someone else.
        'vendor_id' => $other->id,
    ])->assertRedirect();

    $product = Product::firstWhere('name', 'Hand-loom saree');

    expect($product->vendor_id)->toBe($store->id);
});

test('another store\'s product is a 404 on every route that takes an id', function () {
    sellerOnPanel();

    $theirs = Product::factory()->create([
        'vendor_id' => Vendor::factory()->create(['status' => 'approved'])->id,
    ]);

    $this->get(route('seller.products.edit', $theirs))->assertNotFound();
    $this->get(route('seller.products.show', $theirs))->assertNotFound();
    $this->put(route('seller.products.update', $theirs), [
        'name' => 'Renamed by the wrong seller',
        'type' => 'simple',
        'price' => 1,
        'status' => 'draft',
        'stock_quantity' => 0,
    ])->assertNotFound();
    $this->delete(route('seller.products.destroy', $theirs))->assertNotFound();

    expect($theirs->fresh())->not->toBeNull()
        ->and($theirs->fresh()->name)->not->toBe('Renamed by the wrong seller');
});

test('a bulk action silently drops ids belonging to another store', function () {
    [, $store] = sellerOnPanel();

    $mine = Product::factory()->create(['vendor_id' => $store->id, 'status' => 'draft']);
    $theirs = Product::factory()->create([
        'vendor_id' => Vendor::factory()->create(['status' => 'approved'])->id,
        'status' => 'draft',
    ]);

    $this->post(route('seller.products.bulk'), [
        'action' => 'activate',
        'ids' => [$mine->id, $theirs->id],
    ])->assertRedirect();

    expect($mine->fresh()->status)->toBe('active')
        ->and($theirs->fresh()->status)->toBe('draft');
});

test('marketplace staff are sent to their own panel instead', function () {
    $this->actingAs(User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]));

    $this->get('/seller')->assertRedirect('/admin');
    $this->get(route('seller.products.index'))->assertRedirect('/admin');
});

test('a store that cannot trade yet cannot reach the catalog', function () {
    sellerOnPanel(['status' => 'pending']);

    $this->get(route('seller.products.index'))->assertForbidden();
});

test('the role matrix closes the panel section and the API together', function () {
    sellerOnPanel();

    // Same row the seller app is gated on — drop "products" from it and both
    // surfaces shut, rather than the panel becoming a way around the matrix.
    Roles::save('vendor', array_values(array_diff(
        Roles::forRole('vendor'),
        ['products'],
    )));

    $this->get(route('seller.products.index'))->assertForbidden();
    $this->get(route('seller.products.create'))->assertForbidden();
});

test('the panel grant reaches further than the admin panel allows a vendor', function () {
    [$user] = sellerOnPanel();

    // The admin panel is shut to vendors outright; their grant still opens
    // the seller panel, which scopes every query by store.
    expect(Roles::forPanel($user))->toBe([])
        ->and(Roles::forRole('vendor'))->toContain('products');

    $this->get(route('seller.products.index'))->assertOk();
    $this->get(route('admin.products.index'))->assertRedirect('/seller');
});

test('a deactivated seller is signed out rather than left on a 403', function () {
    [$user] = sellerOnPanel();

    $user->forceFill(['is_active' => false])->save();

    $this->get(route('seller.products.index'))->assertRedirect(route('login'));
    $this->assertGuest();
});
