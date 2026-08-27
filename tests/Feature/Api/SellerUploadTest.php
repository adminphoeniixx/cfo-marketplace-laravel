<?php

use Illuminate\Http\Client\ConnectionException;
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
        'connect_timeout' => 5,
        'timeout' => 20,
    ]);
});

test('a seller photo lands under its own store folder', function () {
    [, $store] = actingAsSeller();

    Http::fake(['storage.bunnycdn.com/*' => Http::response('', 201)]);

    $response = $this->postJson(route('api.seller.uploads.store'), [
        'file' => UploadedFile::fake()->image('tent.jpg'),
        'folder' => 'products',
    ])->assertCreated();

    // The store id comes from the token, never the payload, so one seller's
    // photos cannot land in — or overwrite — another's folder.
    expect($response->json('path'))->toStartWith("cfo/products/{$store->id}/")
        ->and($response->json('url'))->toBe('https://test-zone.b-cdn.net/'.$response->json('path'));
});

test('a token is required before anything reaches bunny', function () {
    Http::fake();

    $this->postJson(route('api.seller.uploads.store'), [
        'file' => UploadedFile::fake()->image('tent.jpg'),
        'folder' => 'products',
    ])->assertStatus(401);

    Http::assertNothingSent();
});

test('an unreachable storage zone answers 502 json rather than an unhandled 500', function () {
    actingAsSeller();

    // The real-world shape of this: egress from the container is blocked or
    // slow, so the PUT never completes. It must come back as this endpoint's
    // own error, in time for the app to show a retry.
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    $this->postJson(route('api.seller.uploads.store'), [
        'file' => UploadedFile::fake()->image('tent.jpg'),
        'folder' => 'products',
    ])->assertStatus(502)->assertJsonPath('message', 'Upload failed. Please try again.');
});

test('a rejected bunny request answers 502 too', function () {
    actingAsSeller();

    Http::fake(['storage.bunnycdn.com/*' => Http::response('unauthorized', 401)]);

    $this->postJson(route('api.seller.uploads.store'), [
        'file' => UploadedFile::fake()->image('tent.jpg'),
        'folder' => 'products',
    ])->assertStatus(502);
});

test('only images in the seller folders are accepted', function () {
    actingAsSeller();

    Http::fake();

    $this->postJson(route('api.seller.uploads.store'), [
        'file' => UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf'),
        'folder' => 'products',
    ])->assertStatus(422)->assertJsonValidationErrors('file');

    $this->postJson(route('api.seller.uploads.store'), [
        'file' => UploadedFile::fake()->image('tent.jpg'),
        'folder' => 'categories',
    ])->assertStatus(422)->assertJsonValidationErrors('folder');

    $this->postJson(route('api.seller.uploads.store'), [
        'file' => UploadedFile::fake()->image('huge.jpg')->size(6000),
        'folder' => 'products',
    ])->assertStatus(422)->assertJsonValidationErrors('file');

    Http::assertNothingSent();
});
