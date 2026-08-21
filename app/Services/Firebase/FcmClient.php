<?php

namespace App\Services\Firebase;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Talks to FCM's HTTP v1 API.
 *
 * v1 wants a short-lived OAuth access token rather than the old static server
 * key, so every send is preceded by one: a JWT signed with the service
 * account's private key, exchanged at Google's token endpoint. Google issues
 * those for an hour; we cache for slightly less and let it re-mint.
 *
 * Signing is done with plain openssl rather than pulling in the Google client
 * — it is one call, and the credentials never leave the process.
 */
class FcmClient
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const ENDPOINT = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';

    private bool $resolved = false;

    public function __construct(private ?ServiceAccount $account = null)
    {
        $this->resolved = $account !== null;
    }

    /**
     * Push is on only when it is not switched off and the credentials parse.
     */
    public function enabled(): bool
    {
        return (bool) config('firebase.enabled') && $this->account() !== null;
    }

    public function projectId(): ?string
    {
        return $this->account()?->projectId();
    }

    /**
     * Deliver one message to one device.
     *
     * @param  array<string, mixed>  $message  the FCM `message` object, minus the token
     */
    public function send(string $deviceToken, array $message): FcmResult
    {
        if (! $this->enabled()) {
            return new FcmResult(false, 0, 'DISABLED', 'Firebase push is not configured.');
        }

        try {
            $token = $this->accessToken();
        } catch (Throwable $e) {
            report($e);

            // A token we could not mint is a server-side problem, so this is
            // worth retrying rather than dropping the device.
            return new FcmResult(false, 0, 'AUTH_FAILED', $e->getMessage());
        }

        $url = sprintf(self::ENDPOINT, $this->accountOrFail()->projectId());

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->acceptJson()
                ->post($url, ['message' => ['token' => $deviceToken] + $message]);
        } catch (ConnectionException $e) {
            return new FcmResult(false, 0, 'UNREACHABLE', $e->getMessage());
        }

        if ($response->successful()) {
            return FcmResult::ok();
        }

        return new FcmResult(
            success: false,
            status: $response->status(),
            // v1 buries the useful code one level down; the top-level status
            // is the fallback when it is absent.
            error: $response->json('error.details.0.errorCode')
                ?? $response->json('error.status'),
            message: $response->json('error.message'),
        );
    }

    /**
     * A Google OAuth access token for the messaging scope.
     *
     * Cached per service account, so rotating the credentials naturally
     * invalidates the old one instead of leaving a stale token behind.
     */
    public function accessToken(): string
    {
        $account = $this->accountOrFail();

        return Cache::remember(
            'firebase:access-token:'.sha1($account->clientEmail()),
            // Google issues these for an hour; stop five minutes short so a
            // slow queue worker never sends with an expired one.
            3300,
            fn () => $this->mintAccessToken($account),
        );
    }

    /** Drop the cached token — used after the credentials change. */
    public function forgetAccessToken(): void
    {
        if ($account = $this->account()) {
            Cache::forget('firebase:access-token:'.sha1($account->clientEmail()));
        }
    }

    private function mintAccessToken(ServiceAccount $account): string
    {
        $response = Http::asForm()
            ->timeout(15)
            ->post($account->tokenUri(), [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $this->assertion($account),
            ]);

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token)) {
            throw new RuntimeException(sprintf(
                'Firebase refused the service account: [%d] %s',
                $response->status(),
                (string) ($response->json('error_description') ?? $response->body()),
            ));
        }

        return $token;
    }

    /**
     * The signed JWT that is traded for an access token.
     */
    private function assertion(ServiceAccount $account): string
    {
        $now = time();

        $segments = [
            $this->encode(['alg' => 'RS256', 'typ' => 'JWT']),
            $this->encode([
                'iss' => $account->clientEmail(),
                'scope' => self::SCOPE,
                'aud' => $account->tokenUri(),
                'iat' => $now,
                'exp' => $now + 3600,
            ]),
        ];

        $input = implode('.', $segments);
        $key = openssl_pkey_get_private($account->privateKey());

        if ($key === false) {
            throw new RuntimeException('The Firebase private key could not be read. Check that the newlines survived the environment variable.');
        }

        if (! openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the Firebase assertion.');
        }

        return $input.'.'.$this->base64Url($signature);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function encode(array $data): string
    {
        return $this->base64Url(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * Read the credentials once per instance — `enabled()` is called on every
     * notification and re-reading the file each time would be wasteful.
     */
    private function account(): ?ServiceAccount
    {
        if (! $this->resolved) {
            $this->account = ServiceAccount::resolve();
            $this->resolved = true;
        }

        return $this->account;
    }

    private function accountOrFail(): ServiceAccount
    {
        return $this->account() ?? ServiceAccount::resolveOrFail();
    }
}
