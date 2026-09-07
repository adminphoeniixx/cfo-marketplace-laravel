<?php

namespace App\Services\Couriers;

use App\Models\Order;
use RuntimeException;

/**
 * What every courier has to be able to do before this marketplace can use it.
 *
 * Seven things, and the split between them matters. The first four answer
 * questions or start a parcel moving; the last three are the ones a seller
 * standing at a packing table actually needs — a label to stick on the box, a
 * van to come and get it, and a way to call the whole thing off when the
 * shopper changes their mind before either has happened.
 *
 * Everything past that — slot windows, NDR workflows, weight disputes —
 * differs so wildly between couriers that an interface covering it would be an
 * interface only one of them fits. Those stay on the clients themselves.
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

    /**
     * The label for a parcel, as a URL somebody can open and print.
     *
     * A URL rather than bytes on purpose: both providers hand back a link to a
     * PDF they host, and proxying it would mean this marketplace storing and
     * serving documents whose only reader is the seller who asked for it.
     *
     * Null where the courier could not be asked or has no label to give —
     * which is normal right after booking, before the parcel is manifested.
     */
    public function label(string $waybill): ?string;

    /**
     * Ask for a van, and say when it is coming.
     *
     * Couriers disagree about what a pickup even is: one books it against a
     * warehouse and a package count, the other against individual shipments.
     * Both are given the waybills going out and allowed to use as much of that
     * as their API cares about.
     *
     * @param  list<string>  $waybills  The parcels waiting to be collected.
     * @param  string|null  $date  `Y-m-d`; the courier's own default when null.
     * @return array{scheduled: bool, reference: string|null, message: string|null}|null
     *                                                                                   Null where the courier could not be asked at all.
     */
    public function schedulePickup(array $waybills, ?string $date = null): ?array;

    /**
     * Call a booking off, before anybody has collected the parcel.
     *
     * False covers both refusal and unreachability, because the caller's next
     * move is the same either way: leave the waybill alone and say so. A
     * courier that has already picked the parcel up will refuse, and it is
     * right to.
     */
    public function cancel(string $waybill): bool;
}
