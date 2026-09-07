<?php

namespace App\Models;

use App\Contracts\PushDevice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One shopper install that Firebase will deliver push to.
 *
 * Same contract as the seller app's `DeviceToken`, including the important
 * part: the row is keyed by the token's hash, not by the person, so a shared
 * phone that signs out and back in as someone else moves the token across
 * instead of delivering one shopper's orders to another.
 */
class CustomerDeviceToken extends Model implements PushDevice
{
    public const PLATFORMS = ['android', 'ios', 'web'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_sent_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public static function hashFor(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function remember(Customer $customer, string $token, string $platform = 'android', ?string $deviceName = null): self
    {
        return self::updateOrCreate(
            ['token_hash' => self::hashFor($token)],
            [
                'customer_id' => $customer->getKey(),
                'token' => $token,
                'platform' => in_array($platform, self::PLATFORMS, true) ? $platform : 'android',
                'device_name' => $deviceName,
            ],
        );
    }

    public function pushToken(): string
    {
        return (string) $this->token;
    }

    public function markPushSent(): void
    {
        $this->forceFill(['last_sent_at' => now()])->save();
    }
}
