<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Roles;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $role
 * @property int|null $vendor_id
 * @property bool $is_active
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Mirrors the column defaults so a freshly made model answers `is_active`
     * before it has been round-tripped through the database.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'staff',
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The vendor this user sells for. Only set for users with the vendor role.
     *
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * A vendor user is limited to their own store; every other role is treated
     * as staff of the marketplace itself.
     */
    public function isVendor(): bool
    {
        return $this->role === 'vendor' && $this->vendor_id !== null;
    }

    /**
     * Admins hold every section and are the only ones who can edit the role
     * matrix, so this is checked directly rather than through a permission.
     */
    public function isAdmin(): bool
    {
        return $this->role === Roles::ADMIN;
    }

    /**
     * Whether this user's role may open the given admin section.
     */
    public function canAccess(string $section): bool
    {
        return Roles::allows($this, $section);
    }

    /**
     * Sections this user's role may open.
     *
     * @return list<string>
     */
    public function sections(): array
    {
        return $this->is_active ? Roles::forRole($this->role) : [];
    }
}
