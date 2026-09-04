<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Delivery dates, in the words that go on the screen.
 *
 * The app was building these strings itself out of `delivery_days_min` and
 * `delivery_days_max`, with a hardcoded fallback wherever a rate had neither —
 * which meant the checkout screen and the order screen could promise two
 * different days for the same parcel. One formatter, used by the quote and by
 * the order, is what makes those agree.
 */
class Eta
{
    /**
     * A day count from a seller's rate turned into two dates.
     *
     * Either end may be missing on a rate the seller half-filled in, so a lone
     * figure means "that day", and neither means no promise at all.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}|null
     */
    public static function window(?int $minDays, ?int $maxDays, ?CarbonInterface $from = null): ?array
    {
        if ($minDays === null && $maxDays === null) {
            return null;
        }

        $base = ($from ?? Carbon::now())->copy()->startOfDay();
        $min = $minDays ?? $maxDays;
        $max = $maxDays ?? $minDays;

        return [$base->copy()->addDays((int) $min), $base->copy()->addDays((int) $max)];
    }

    /**
     * "Arrives Mon, 1 Sep" · "Arrives 4–6 Sep" · "Arrives 28 Sep – 2 Oct".
     */
    public static function label(?CarbonInterface $from, ?CarbonInterface $to, string $verb = 'Arrives'): ?string
    {
        $from ??= $to;
        $to ??= $from;

        if (! $from || ! $to) {
            return null;
        }

        return trim($verb.' '.self::range($from, $to));
    }

    /**
     * The date part on its own: one day named in full, a same-month window
     * sharing its month, and a window across two months spelling out both.
     */
    public static function range(CarbonInterface $from, CarbonInterface $to): string
    {
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        if ($from->isSameDay($to)) {
            return $from->format('D, j M');
        }

        return $from->isSameMonth($to) && $from->isSameYear($to)
            ? $from->format('j').'–'.$to->format('j M')
            : $from->format('j M').' – '.$to->format('j M');
    }
}
