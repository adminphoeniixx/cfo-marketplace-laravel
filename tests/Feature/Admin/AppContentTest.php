<?php

use App\Models\Banner;
use App\Models\Faq;
use App\Models\LegalPage;
use App\Models\Vendor;

/*
| The screen behind the app's banners, help answers and legal pages — the one
| that means changing any of them is not a release.
*/

beforeEach(function () {
    actingAsAdmin();
});

test('the content screen lists all three, and says what the app is showing', function () {
    Banner::factory()->create(['title' => 'Festive edit']);
    Banner::factory()->expired()->create(['title' => 'Last summer']);
    Faq::factory()->create();

    $this->get(route('admin.content.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/content/Index')
            ->has('banners', 2)
            ->has('faqs', 1)
            // The five legal slugs ship with the schema.
            ->has('pages', 5)
            ->where('banners.0.is_live', true)
            // Switched on, but its dates have passed: the app is not drawing
            // it, and the screen must not claim otherwise.
            ->where('banners.1.is_active', true)
            ->where('banners.1.is_live', false)
        );
});

test('a banner is added, hidden and deleted', function () {
    $this->post(route('admin.content.banners.store'), [
        'title' => 'Festive edit',
        'subtitle' => 'Up to 40% off handloom',
        'deeplink_route' => 'category',
        'position' => 0,
        'is_active' => true,
    ])->assertRedirect();

    $banner = Banner::firstOrFail();
    expect($banner->title)->toBe('Festive edit');

    $this->patch(route('admin.content.banners.toggle', $banner->id))->assertRedirect();
    expect($banner->fresh()->is_active)->toBeFalse();

    $this->delete(route('admin.content.banners.destroy', $banner->id))->assertRedirect();
    expect(Banner::count())->toBe(0);
});

test('a banner cannot end before it starts', function () {
    $this->post(route('admin.content.banners.store'), [
        'title' => 'Festive edit',
        'starts_at' => now()->addWeek()->toDateString(),
        'ends_at' => now()->toDateString(),
    ])->assertSessionHasErrors('ends_at');
});

test('a legal page is written and published, and only then does the app see it', function () {
    $page = LegalPage::where('slug', 'privacy')->firstOrFail();

    $this->put(route('admin.content.pages.update', $page->id), [
        'title' => 'Privacy policy',
        'body' => 'What we keep, and for how long.',
        'is_published' => true,
    ])->assertRedirect();

    expect($page->fresh()->is_published)->toBeTrue();

    $this->getJson(route('api.customer.legal.show', 'privacy'))
        ->assertOk()
        ->assertJsonPath('data.body', 'What we keep, and for how long.');
});

test('the assured badge and cash on delivery are the marketplace\'s call', function () {
    $vendor = Vendor::factory()->create(['status' => 'approved', 'is_assured' => false]);

    $this->put(route('admin.vendors.update', $vendor->id), [
        ...$vendor->only([
            'name', 'store_email', 'phone', 'status', 'commission_type',
            'commission_rate', 'country', 'payout_method',
        ]),
        'is_assured' => true,
        'cod_available' => false,
    ])->assertRedirect();

    expect($vendor->fresh()->is_assured)->toBeTrue()
        ->and($vendor->fresh()->cod_available)->toBeFalse();
});

test('a role without store settings cannot reach the content screen', function () {
    actingAsAdmin(['role' => 'staff']);

    $this->get(route('admin.content.index'))->assertForbidden();
});
