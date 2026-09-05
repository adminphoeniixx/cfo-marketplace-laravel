<?php

namespace App\Services\Couriers;

use App\Models\Order;
use RuntimeException;

/**
 * What every courier has to be able to do before this marketplace can use it.
 *
 * Kept to four things on purpose. Couriers differ wildly in what else they
 * offer — pickup slots, label formats, NDR workflows — and an interface that
 * tried to cover all of it would be an interface only one of them fits.
 */
interface CourierClient
{
    /**
     * Prove the credentials work, without shipping anything.
     *
     * This is what the panel's Test button calls. A courier that answers 200
     * to a booking and delivers nothing is the failure this marketplace has
     * already met once, so being able to ask "are these keys real" before a
     * parcel depends on it is worth a method of its own.
     */
    public function ping(): bool;

    /**
     * Book one order and hand back its waybill.
     *
     * @param  array<string, mixed>  $overrides  Weight, dimensions, seller name.
     *
     * @throws RuntimeException When the courier refuses or cannot be reached.
     */
    public function createShipment(Order $order, array $overrides = []): string;

    /**
     * Where a parcel has got to.
     *
     * Null means the courier could not be asked — which is not the same as
     * "nothing has happened", and callers must not treat it as such.
     *
     * @return array{status: string, raw_status: string, location: string|null, updated_at: string|null, scans: list<array<string, mixed>>}|null
     */
    public function track(string $waybill): ?array;

    /**
     * Whether this courier delivers to a pincode, and on what terms.
     *
     * @return array{serviceable: bool, cod: bool, prepaid: bool, pickup: bool}|null
     */
    public function serviceability(string $pincode): ?array;
}
