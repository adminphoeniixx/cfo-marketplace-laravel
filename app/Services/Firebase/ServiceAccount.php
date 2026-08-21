<?php

namespace App\Services\Firebase;

use JsonException;
use RuntimeException;

/**
 * The Firebase service account JSON, however it reached us.
 *
 * Reading it is deliberately forgiving: a hosted deployment usually cannot put
 * a file on disk, so the same setting also accepts the JSON itself or a base64
 * copy of it pasted into an environment variable.
 */
class ServiceAccount
{
    /**
     * @param  array<string, string>  $data
     */
    private function __construct(private readonly array $data) {}

    /**
     * Null rather than an exception when nothing is configured — push being
     * switched off is a normal state, not a broken one.
     */
    public static function resolve(): ?self
    {
        $source = config('firebase.credentials');

        if (! is_string($source) || trim($source) === '') {
            return null;
        }

        $json = self::read(trim($source));

        if ($json === null) {
            return null;
        }

        try {
            /** @var array<string, string> $data */
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        // Anything missing these three cannot sign a token, so treat a
        // half-filled file the same as no file at all.
        foreach (['client_email', 'private_key', 'project_id'] as $key) {
            if (empty($data[$key])) {
                return null;
            }
        }

        return new self($data);
    }

    public static function resolveOrFail(): self
    {
        return self::resolve() ?? throw new RuntimeException(
            'No usable Firebase credentials. Set FIREBASE_CREDENTIALS to the service account JSON, its path, or a base64 copy of it.'
        );
    }

    private static function read(string $source): ?string
    {
        // Already the JSON itself.
        if (str_starts_with($source, '{')) {
            return $source;
        }

        // A readable path on disk.
        if (is_file($source) && is_readable($source)) {
            return file_get_contents($source) ?: null;
        }

        // Base64 of the JSON — what gets pasted into a hosting panel.
        $decoded = base64_decode($source, true);

        return is_string($decoded) && str_starts_with(ltrim($decoded), '{') ? $decoded : null;
    }

    public function projectId(): string
    {
        return (string) (config('firebase.project_id') ?: $this->data['project_id']);
    }

    public function clientEmail(): string
    {
        return $this->data['client_email'];
    }

    public function privateKey(): string
    {
        // Environment variables cannot hold real newlines, so a key pasted
        // into one arrives with the breaks escaped.
        return str_replace('\n', "\n", $this->data['private_key']);
    }

    public function tokenUri(): string
    {
        return $this->data['token_uri'] ?? 'https://oauth2.googleapis.com/token';
    }
}
