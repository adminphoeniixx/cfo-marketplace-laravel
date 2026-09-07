<?php

namespace App\Models;

use App\Contracts\PushDevice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One phone (or browser) that Firebase will deliver push to.
 *
 * The token belongs to an install, not to a login: reinstalling the app hands
 * out a new one and silently retires the old, which is why deliveries drop
 * rows rather than retry them.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token
 * @property string $token_hash
 * @property string $platform
 * @property string|null $device_name
 * @property Carbon|null $last_sent_at
 */
class DeviceToken extends Model implements PushDevice
{
    protected $guarded = [];

    /** Platforms the seller app may register as. */
    public const PLATFORMS = ['android', 'ios', 'web'];

    protected function casts(): array
    {
        return ['last_sent_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hashFor(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Remember a token for this user.
     *
     * Matching on the hash rather than on the user is deliberate: a shared
     * tablet that signs out and back in as someone else must move the token
     * across, not end up delivering one person's orders to another.
     */
    public static function remember(User $user, string $token, string $platform = 'android', ?string $deviceName = null): self
    {
        return self::updateOrCreate(
            ['token_hash' => self::hashFor($token)],
            [
                'user_id' => $user->getKey(),
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
