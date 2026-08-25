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
            'description' => 'Seller login. Drives the seller app and the seller panel, where everything is scoped to their own store. A vendor reaches no screen in this admin panel at all.',
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
        // Everything the seller app offers. The API enforces this list too, so
        // narrowing it here closes the matching screen in the app as well as
        // the section in the panel.
        'vendor' => [
            'analytics', 'orders', 'cancellations', 'refunds',
            'products', 'shipping', 'payouts', 'team',
        ],
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
     * Sections a vendor login may open **in the admin panel**: none.
     *
     * A vendor's matrix row governs two surfaces that both scope every query to
     * their own store — the seller API and the seller panel at /seller. This
     * admin panel scopes to the marketplace instead, so nothing here is safe to
     * hand a vendor.
     *
     * It used to list Analytics and Orders. Analytics did scope, through
     * `AnalyticsController::resolveVendor()`. Orders did not: `index()` took a
     * vendor id only as an optional *filter*, so a signed-in vendor was served
     * every marketplace order, a marketplace-wide revenue figure, and — on
     * `show()` — another store's commission and earnings. Both screens now live
     * under /seller, scoped by `ScopesToStore`, and this list is empty.
     *
     * Keep it empty. A screen that a seller needs belongs in the seller panel,
     * where scoping is the default rather than something each query remembers.
     *
     * @var list<string>
     */
    public const VENDOR_PANEL_SECTIONS = [];

    /**
     * @return list<string>
     */
    public static function forRole(?string $role): array
    {
        return self::permissions()[$role] ?? [];
    }

    /**
     * The grant as the admin panel sees it — the role's sections, narrowed for
     * a vendor to those the panel can safely show.
     *
     * @return list<string>
     */
    public static function forPanel(?User $user): array
    {
        if (! $user instanceof User || ! $user->is_active) {
            return [];
        }

        $sections = self::forRole($user->role);

        return $user->isVendor()
            ? array_values(array_intersect($sections, self::VENDOR_PANEL_SECTIONS))
            : $sections;
    }

    /**
     * Whether the user may open the given section in the admin panel.
     */
    public static function allowsInPanel(?User $user, string $section): bool
    {
        return in_array($section, self::forPanel($user), true);
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
     * Whether the user's role holds the given section at all.
     *
     * This is the grant itself, which the seller API enforces directly. The
     * admin panel asks `allowsInPanel()` instead, because a vendor's grant
     * reaches further than the panel can currently scope.
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
