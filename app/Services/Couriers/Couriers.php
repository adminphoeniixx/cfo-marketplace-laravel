<?php

namespace App\Services\Couriers;

use App\Models\DeliveryPartner;

/**
 * Which client, if any, knows how to talk to a given courier.
 *
 * The one place that turns a row in the panel into something that can book a
 * parcel. A partner with no `driver` — or with one whose credentials nobody
 * has filled in — resolves to null, and every caller treats that the way it
 * always did: the seller types a waybill in by hand.
 *
 * Delhivery falls back to the environment, so a marketplace that configured it
 * before this table could hold credentials keeps working untouched.
 */
class Couriers
{
    /**
     * The drivers this marketplace can speak, and what each needs to be told.
     *
     * Drives the panel's own form: a field appears because a driver asked for
     * it, so adding a courier is a client and an entry here rather than an
     * entry here, a client, and a forgotten input.
     *
     * @var array<string, array{label: string, fields: array<string, array{label: string, secret: bool, help: string}>}>
     */
    public const DRIVERS = [
        'delhivery' => [
            'label' => 'Delhivery',
            'fields' => [
                'token' => [
                    'label' => 'API token',
                    'secret' => true,
                    'help' => 'From the Delhivery panel, under API setup.',
                ],
                'pickup_name' => [
                    'label' => 'Pickup warehouse',
                    'secret' => false,
                    'help' => 'Exactly as registered with Delhivery. A name they do not know refuses every booking.',
                ],
                'seller_name' => [
                    'label' => 'Sender name on the label',
                    'secret' => false,
                    'help' => 'Optional. Defaults to the store name.',
                ],
            ],
        ],
        'shiprocket' => [
            'label' => 'Shiprocket',
            'fields' => [
                'email' => [
                    'label' => 'Account email',
                    'secret' => false,
                    'help' => 'The Shiprocket login. Their token is a sign-in, not a key.',
                ],
                'password' => [
                    'label' => 'Password',
                    'secret' => true,
                    'help' => 'Held encrypted. Used only to fetch a token, which lasts ten days.',
                ],
                'pickup_location' => [
                    'label' => 'Pickup location',
                    'secret' => false,
                    'help' => 'The nickname of a pickup address in Shiprocket. Defaults to "Primary".',
                ],
            ],
        ],
    ];

    /**
     * A client for this partner, or null where it is only a name and a link.
     */
    public static function for(?DeliveryPartner $partner): ?CourierClient
    {
        if (! $partner instanceof DeliveryPartner || ! $partner->is_active) {
            return null;
        }

        $credentials = $partner->credentials ?? [];

        return match ($partner->driver) {
            'delhivery' => self::delhivery($credentials),
            'shiprocket' => self::shiprocket($credentials),
            default => null,
        };
    }

    /**
     * The courier a carrier name resolves to, which is how an order — which
     * records a name, not a foreign key — finds its client.
     */
    public static function forCarrier(?string $carrier): ?CourierClient
    {
        if (empty($carrier)) {
            return null;
        }

        $partner = DeliveryPartner::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower($carrier)])
            ->orWhereRaw('lower(code) = ?', [mb_strtolower($carrier)])
            ->first();

        return self::for($partner);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private static function delhivery(array $credentials): ?DelhiveryClient
    {
        // The environment is the fallback, not the other way round: a token
        // typed into the panel is the more recent decision.
        $token = self::value($credentials, 'token') ?? self::config('delhivery', 'token');
        $pickup = self::value($credentials, 'pickup_name') ?? self::config('delhivery', 'pickup_name');

        if ($token === null || $pickup === null) {
            return null;
        }

        return new DelhiveryClient(
            token: $token,
            pickupName: $pickup,
            sellerName: self::value($credentials, 'seller_name') ?? self::config('delhivery', 'seller_name'),
            baseUrl: self::config('delhivery', 'base_url') ?? 'https://track.delhivery.com',
            connectTimeout: (int) (self::config('delhivery', 'connect_timeout') ?? 5),
            timeout: (int) (self::config('delhivery', 'timeout') ?? 30),
        );
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private static function shiprocket(array $credentials): ?ShiprocketClient
    {
        $email = self::value($credentials, 'email') ?? self::config('shiprocket', 'email');
        $password = self::value($credentials, 'password') ?? self::config('shiprocket', 'password');

        if ($email === null || $password === null) {
            return null;
        }

        return new ShiprocketClient(
            email: $email,
            password: $password,
            pickupLocation: self::value($credentials, 'pickup_location') ?? self::config('shiprocket', 'pickup_location'),
        );
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private static function value(array $credentials, string $key): ?string
    {
        $value = $credentials[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private static function config(string $driver, string $key): ?string
    {
        $value = config("services.{$driver}.{$key}");

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
