<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Thin wrapper around BunnyCDN Edge Storage for public file uploads.
 *
 * Usage:
 *   $path = BunnyCdn::upload($request->file('avatar'), 'avatars'); // -> "avatars/uuid.jpg"
 *   $url  = BunnyCdn::url($path);                                  // -> pull-zone URL
 *   BunnyCdn::delete($path);
 *
 * Stored paths are relative to the storage zone (e.g. "avatars/uuid.jpg") and are
 * what you persist on the model. Turn one into a public URL with url().
 */
class BunnyCdn
{
    /**
     * Store an uploaded file under $directory and return its storage-zone path.
     */
    public static function upload(UploadedFile $file, string $directory = '', ?string $name = null): string
    {
        $name ??= Str::uuid()->toString().'.'.($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');

        $path = self::join($directory, $name);

        self::put($path, (string) file_get_contents($file->getRealPath()), $file->getMimeType() ?: 'application/octet-stream');

        return $path;
    }

    /**
     * Store raw contents at an exact storage-zone path and return that path.
     */
    public static function putContents(string $path, string $contents, string $contentType = 'application/octet-stream'): string
    {
        $path = ltrim($path, '/');
        self::put($path, $contents, $contentType);

        return $path;
    }

    /**
     * Delete a file by its storage-zone path. Returns false on a missing path or
     * a failed request; never throws, so it is safe to call on stale paths.
     */
    public static function delete(?string $path): bool
    {
        if (empty($path)) {
            return false;
        }

        return Http::withHeaders(['AccessKey' => self::config('api_key')])
            ->delete(self::storageUrl($path))
            ->successful();
    }

    /**
     * Public pull-zone URL for a stored path, or null when the path is empty.
     *
     * When a token-auth key is configured (the pull zone has Token Authentication
     * enabled), this returns a signed, time-limited URL; otherwise a plain URL.
     */
    public static function url(?string $path): ?string
    {
        if (empty($path)) {
            return null;
        }

        $base = rtrim((string) self::config('pull_zone_url'), '/');
        $relative = '/'.ltrim($path, '/');
        $key = self::config('token_auth_key');

        if (empty($key)) {
            return $base.$relative;
        }

        $expires = time() + ((int) self::config('url_ttl') ?: 604800);

        // Bunny token auth: url-safe base64 of the raw SHA-256 of key+path+expires.
        $token = strtr(base64_encode(hash('sha256', $key.$relative.$expires, true)), '+/', '-_');
        $token = str_replace('=', '', $token);

        return "{$base}{$relative}?token={$token}&expires={$expires}";
    }

    /**
     * Resolve a stored image value to something an <img> tag can use.
     *
     * Rows written before BunnyCDN hold a full URL (and seeded demo data still
     * does), so anything absolute is passed straight through; everything else is
     * treated as a storage-zone path and signed.
     */
    public static function display(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        return Str::startsWith($value, ['http://', 'https://']) ? $value : self::url($value);
    }

    /**
     * PUT raw bytes to the storage zone, throwing on any non-success response.
     */
    private static function put(string $path, string $contents, string $contentType): void
    {
        $response = Http::withHeaders(['AccessKey' => self::config('api_key')])
            ->withBody($contents, $contentType)
            ->put(self::storageUrl($path));

        if (! $response->successful()) {
            throw new RuntimeException("BunnyCDN upload failed ({$response->status()}): {$response->body()}");
        }
    }

    /**
     * Full storage API endpoint for a given path.
     */
    private static function storageUrl(string $path): string
    {
        $region = trim((string) self::config('region'));
        $host = trim((string) self::config('host')) ?: 'storage.bunnycdn.com';
        $host = $region !== '' ? "{$region}.{$host}" : $host;
        $zone = trim((string) self::config('storage_zone'), '/');

        return "https://{$host}/{$zone}/".ltrim($path, '/');
    }

    private static function join(string $directory, string $name): string
    {
        $directory = trim($directory, '/');

        return $directory === '' ? $name : "{$directory}/{$name}";
    }

    private static function config(string $key): ?string
    {
        return config("services.bunnycdn.{$key}");
    }
}
