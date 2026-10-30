<?php

namespace App\Support;

/**
 * Every state and union territory a parcel can be addressed to.
 *
 * Served to the app through `/reference` so its address form offers the same
 * list the API accepts — a picker hardcoded in the client shipped with six
 * states and no way to reach the other thirty. The names are the spellings
 * shipping zones and tax invoices match on, which is why a free-typed
 * "tamil nadu" is folded onto "Tamil Nadu" rather than stored as typed.
 */
class IndianStates
{
    /** @var list<string> */
    public const STATES = [
        'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh',
        'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jharkhand', 'Karnataka',
        'Kerala', 'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya',
        'Mizoram', 'Nagaland', 'Odisha', 'Punjab', 'Rajasthan', 'Sikkim',
        'Tamil Nadu', 'Telangana', 'Tripura', 'Uttar Pradesh', 'Uttarakhand',
        'West Bengal',
    ];

    /** @var list<string> */
    public const UNION_TERRITORIES = [
        'Andaman and Nicobar Islands', 'Chandigarh',
        'Dadra and Nagar Haveli and Daman and Diu', 'Delhi', 'Jammu and Kashmir',
        'Ladakh', 'Lakshadweep', 'Puducherry',
    ];

    /**
     * All of them, alphabetically — the order a picker shows them in.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        $names = [...self::STATES, ...self::UNION_TERRITORIES];
        sort($names);

        return $names;
    }

    /**
     * The list as the app draws it.
     *
     * @return list<array{name: string, is_union_territory: bool}>
     */
    public static function options(): array
    {
        return array_map(fn (string $name) => [
            'name' => $name,
            'is_union_territory' => in_array($name, self::UNION_TERRITORIES, true),
        ], self::names());
    }

    /**
     * The canonical spelling of a state typed any which way, or null when it
     * is not one.
     */
    public static function canonical(?string $state): ?string
    {
        $key = self::key((string) $state);

        foreach (self::names() as $name) {
            if (self::key($name) === $key) {
                return $name;
            }
        }

        return null;
    }

    private static function key(string $value): string
    {
        return strtolower((string) preg_replace('/\s+/', ' ', trim($value)));
    }
}
