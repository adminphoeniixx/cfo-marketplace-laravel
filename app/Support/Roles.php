<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Arr;

/**
 * The whole access model lives here: users carry a single `role` string, and
 * each role holds a list of admin sections it may open. The admin role is
 * deliberately not stored — it always holds every section, so the panel can
 * never be locked out by editing the matrix.
 */
class Roles
{
    public const ADMIN = 'admin';

    /** Prefix used for the one settings row per editable role. */
    private const SETTING_PREFIX = 'role_permissions.';

    /**
     * Assignable roles, widest reach first.
     *
     * @var array<string, array{label: string, description: string}>
     */
    public const ALL = [
        'admin' => [
            'label' => 'Admin',
            'description' => 'Owner-level access. Always holds every section, including team and roles.',
        ],
        'manager' => [
            'label' => 'Manager',
            'description' => 'Runs the day to day — catalog, orders and partners — without touching billing setup.',
        ],
        'staff' => [
            'label' => 'Staff',
            'description' => 'Support desk. Works through orders, cancellations, refunds and customers.',
        ],
        'vendor' => [
            'label' => 'Vendor',
            'description' => 'Seller login. Everything they open is scoped to their own store.',
        ],
    ];

    /**
     * Areas access can be granted for, keyed the same way the sidebar groups
     * them so one list drives both the matrix and the navigation.
     *
     * @var array<string, array{label: string, group: string}>
     */
    public const SECTIONS = [
        'analytics' => ['label' => 'Analytics', 'group' => 'Overview'],
        'orders' => ['label' => 'Orders', 'group' => 'Sales'],
        'cancellations' => ['label' => 'Cancellations', 'group' => 'Sales'],
        'refunds' => ['label' => 'Refunds', 'group' => 'Sales'],
        'customers' => ['label' => 'Customers', 'group' => 'Sales'],
        'products' => ['label' => 'Products', 'group' => 'Catalog'],
        'categories' => ['label' => 'Categories', 'group' => 'Catalog'],
        'attributes' => ['label' => 'Attributes', 'group' => 'Catalog'],
        'vendors' => ['label' => 'Vendors', 'group' => 'Partners'],
        'payouts' => ['label' => 'Payouts', 'group' => 'Partners'],
        'payments' => ['label' => 'Payments', 'group' => 'Settings'],
        'taxes' => ['label' => 'Taxes', 'group' => 'Settings'],
        'shipping' => ['label' => 'Shipping', 'group' => 'Settings'],
        'settings' => ['label' => 'Store settings', 'group' => 'Settings'],
        'team' => ['label' => 'Team', 'group' => 'Staff'],
    ];

    /**
     * What each role holds until someone edits the matrix.
     *
     * @var array<string, list<string>>
     */
    public const DEFAULTS = [
        'manager' => [
            'analytics', 'orders', 'cancellations', 'refunds', 'customers',
            'products', 'categories', 'attributes', 'vendors', 'payouts',
        ],
        'staff' => ['orders', 'cancellations', 'refunds', 'customers'],
        'vendor' => ['analytics', 'orders', 'products', 'payouts'],
    ];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    /**
     * @return list<string>
     */
    public static function sectionKeys(): array
    {
        return array_keys(self::SECTIONS);
    }

    /**
     * Roles whose section list can be edited. Admin is fixed at full access.
     *
     * @return list<string>
     */
    public static function editable(): array
    {
        return array_values(array_diff(self::keys(), [self::ADMIN]));
    }

    public static function isEditable(string $role): bool
    {
        return in_array($role, self::editable(), true);
    }

    public static function label(string $role): string
    {
        return self::ALL[$role]['label'] ?? ucfirst($role);
    }

    /**
     * Every role's section list, admin included.
     *
     * @return array<string, list<string>>
     */
    public static function permissions(): array
    {
        $stored = Setting::query()
            ->where('key', 'like', self::SETTING_PREFIX.'%')
            ->pluck('value', 'key');

        $permissions = [self::ADMIN => self::sectionKeys()];

        foreach (self::editable() as $role) {
            $raw = $stored[self::SETTING_PREFIX.$role] ?? null;

            $permissions[$role] = $raw === null
                ? (self::DEFAULTS[$role] ?? [])
                // Unknown keys are dropped so a renamed section cannot linger.
                : array_values(array_intersect(
                    self::sectionKeys(),
                    array_filter(explode(',', $raw))
                ));
        }

        return $permissions;
    }

    /**
     * @return list<string>
     */
    public static function forRole(?string $role): array
    {
        return self::permissions()[$role] ?? [];
    }

    /**
     * @param  array<int, string>  $sections
     */
    public static function save(string $role, array $sections): void
    {
        if (! self::isEditable($role)) {
            return;
        }

        $sections = array_values(array_intersect(self::sectionKeys(), $sections));

        Setting::put(self::SETTING_PREFIX.$role, implode(',', $sections), 'roles');
    }

    /**
     * Whether the user may open the given admin section.
     */
    public static function allows(?User $user, string $section): bool
    {
        if (! $user instanceof User || ! $user->is_active) {
            return false;
        }

        return in_array($section, self::forRole($user->role), true);
    }

    /**
     * How many users sit on each role.
     *
     * @return array<string, int>
     */
    public static function memberCounts(): array
    {
        $counts = User::query()
            ->selectRaw('role, count(*) as total')
            ->groupBy('role')
            ->pluck('total', 'role')
            ->all();

        return Arr::mapWithKeys(
            self::keys(),
            fn (string $role) => [$role => (int) ($counts[$role] ?? 0)]
        );
    }
}
