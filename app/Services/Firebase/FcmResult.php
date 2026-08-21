<?php

namespace App\Services\Firebase;

/**
 * What came back from one delivery attempt.
 *
 * `retryable` and `gone` are the only two things a caller really has to act
 * on: retry later, or drop the token and stop writing to a dead phone.
 */
class FcmResult
{
    public function __construct(
        public readonly bool $success,
        public readonly int $status = 0,
        public readonly ?string $error = null,
        public readonly ?string $message = null,
    ) {}

    public static function ok(): self
    {
        return new self(true, 200);
    }

    /**
     * The app was uninstalled, the token was rotated, or it belongs to another
     * Firebase project. None of those fix themselves — delete the row.
     */
    public function gone(): bool
    {
        return in_array($this->error, ['UNREGISTERED', 'INVALID_ARGUMENT', 'SENDER_ID_MISMATCH'], true)
            || $this->status === 404;
    }

    /**
     * FCM being rate limited or briefly down. Worth another go.
     */
    public function retryable(): bool
    {
        return ! $this->success && ! $this->gone()
            && ($this->status === 429 || $this->status >= 500 || $this->status === 0);
    }

    public function describe(): string
    {
        return trim(sprintf('[%d] %s %s', $this->status, $this->error ?? '', $this->message ?? ''));
    }
}
