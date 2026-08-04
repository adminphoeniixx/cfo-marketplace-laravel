<?php

use App\Models\Category;
use App\Services\BunnyCdn;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.bunnycdn', [
        'storage_zone' => 'test-zone',
        'api_key' => 'test-key',
        'region' => '',
        'host' => 'storage.bunnycdn.com',
        'pull_zone_url' => 'https://test-zone.b-cdn.net/',
        'token_auth_key' => null,
        'url_ttl' => 604800,
        'prefix' => 'cfo',
    ]);
});

test('guests cannot upload', function () {
    Http::fake();

    $this->post(route('admin.uploads.store'), [
        'file' => UploadedFile::fake()->image('shoe.jpg'),
        'folder' => 'products',
    ])->assertRedirect(route('login'));

    Http::assertNothingSent();
});

test('an image is pushed to bunny and its path returned', function () {
    actingAsAdmin();

    Http::fake(['storage.bunnycdn.com/*' => Http::response('', 201)]);

    $response = $this->post(route('admin.uploads.store'), [
        'file' => UploadedFile::fake()->image('shoe.jpg'),
        'folder' => 'products',
    ])->assertOk();

    $path = $response->json('path');

    // Uploads are namespaced, because the storage zone is shared.
    expect($path)->toStartWith('cfo/products/')
        ->toEndWith('.jpg')
        ->and($response->json('url'))->toBe('https://test-zone.b-cdn.net/'.$path);

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_starts_with($request->url(), 'https://storage.bunnycdn.com/test-zone/cfo/products/')
        && $request->header('AccessKey') === ['test-key']);
});

test('only images in whitelisted folders are accepted', function () {
    actingAsAdmin();

    Http::fake();

    $this->post(route('admin.uploads.store'), [
        'file' => UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf'),
        'folder' => 'products',
    ])->assertSessionHasErrors('file');

    $this->post(route('admin.uploads.store'), [
        'file' => UploadedFile::fake()->image('shoe.jpg'),
        'folder' => '../../etc',
    ])->assertSessionHasErrors('folder');

    // 5 MB is the ceiling.
    $this->post(route('admin.uploads.store'), [
        'file' => UploadedFile::fake()->image('huge.jpg')->size(6000),
        'folder' => 'products',
    ])->assertSessionHasErrors('file');

    Http::assertNothingSent();
});

test('a failed bunny request surfaces as an error rather than a broken path', function () {
    actingAsAdmin();

    Http::fake(['storage.bunnycdn.com/*' => Http::response('nope', 401)]);

    $this->post(route('admin.uploads.store'), [
        'file' => UploadedFile::fake()->image('shoe.jpg'),
        'folder' => 'products',
    ])->assertStatus(502);
});

test('stored paths resolve to pull zone urls and absolute urls pass through', function () {
    expect(BunnyCdn::display('cfo/products/a.jpg'))
        ->toBe('https://test-zone.b-cdn.net/cfo/products/a.jpg')
        ->and(BunnyCdn::display('https://picsum.photos/seed/x/600/600'))
        ->toBe('https://picsum.photos/seed/x/600/600')
        ->and(BunnyCdn::display(null))->toBeNull();
});

test('urls are signed when the pull zone uses token authentication', function () {
    config()->set('services.bunnycdn.token_auth_key', 'secret-key');

    $url = BunnyCdn::display('cfo/products/a.jpg');

    expect($url)->toContain('https://test-zone.b-cdn.net/cfo/products/a.jpg?token=')
        ->toContain('&expires=');

    // A seeded absolute URL must never be signed.
    expect(BunnyCdn::display('https://picsum.photos/seed/x/600/600'))
        ->not->toContain('token=');
});

test('models expose a renderable url for stored images', function () {
    $category = Category::factory()->create(['image_path' => 'cfo/categories/a.jpg']);

    expect($category->image_url)->toBe('https://test-zone.b-cdn.net/cfo/categories/a.jpg');

    expect(Category::factory()->create(['image_path' => null])->image_url)->toBeNull();
});
