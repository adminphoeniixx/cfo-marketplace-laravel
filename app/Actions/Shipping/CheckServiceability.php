<?php

namespace App\Actions\Shipping;

use App\Models\DeliveryPartner;
use App\Services\Couriers\Couriers;
use Illuminate\Support\Facades\Cache;

/**
 * Whether anybody delivers to a pincode, asked before it matters.
 *
 * The marketplace had every part of this except the question: both clients
 * could answer it and nothing called them. So a shopper in a pincode no
 * courier serves could fill a basket, pay, and find out days later — and a
 * shopper in a pincode that takes prepaid but not cash could choose cash on
 * delivery and have the order fail at the courier rather than at the checkout.
 *
 * Cached hard, because the answer is a property of the pincode rather than of
 * the shopper: it changes when a courier opens a branch, not when somebody
 * opens the app. Twelve hours is well inside that.
 *
 * Every failure here is a *yes*. A courier having a bad morning must not close
 * the checkout — booking will still be attempted, and the seller can still
 * hand the parcel over at a counter. Refusing a sale is the more expensive
 * mistake.
 */
class CheckServiceability
{
    /**
     * The couriers' combined answer for a pincode.
     *
     * @return array{serviceable: bool, cod: bool, prepaid: bool, pickup: bool, checked: bool, carrier: string|null}
     */
    public function handle(string $pincode): array
    {
        $pincode = trim($pincode);

        if (! preg_match('/^\d{6}$/', $pincode)) {
            return $this->unknown();
        }

        return Cache::remember(
            "serviceability:{$pincode}",
            now()->addHours(12),
            fn () => $this->ask($pincode),
        );
    }

    /**
     * Ask each connected courier in turn, and stop at the first that delivers.
     *
     * First rather than best: this answers "can this parcel get there at all",
     * and a second opinion changes nothing a shopper would do differently. The
     * courier that ends up carrying it is chosen at fulfilment, by the seller.
     *
     * @return array{serviceable: bool, cod: bool, prepaid: bool, pickup: bool, checked: bool, carrier: string|null}
     */
    protected function ask(string $pincode): array
    {
        $partners = DeliveryPartner::query()
            ->whereNotNull('driver')
            ->where('is_active', true)
            ->orderBy('position')
            ->get();

        $answered = false;

        foreach ($partners as $partner) {
            $answer = Couriers::for($partner)?->serviceability($pincode);

            if ($answer === null) {
                continue;
            }

            $answered = true;

            if ($answer['serviceable']) {
                return [...$answer, 'checked' => true, 'carrier' => $partner->name];
            }
        }

        // Every courier answered, and all of them said no. This is the only
        // path on which a pincode is reported as unserved — silence never is.
        if ($answered) {
            return [
                'serviceable' => false, 'cod' => false, 'prepaid' => false,
                'pickup' => false, 'checked' => true, 'carrier' => null,
            ];
        }

        return $this->unknown();
    }

    /**
     * Nobody could be asked, so everything is allowed and `checked` says why.
     *
     * Callers use that flag to tell "we know this works" from "we do not
     * know" — the checkout shows the first as a promise and the second as
     * nothing at all, because a promise nobody verified is worse than silence.
     *
     * @return array{serviceable: bool, cod: bool, prepaid: bool, pickup: bool, checked: bool, carrier: string|null}
     */
    protected function unknown(): array
    {
        return [
            'serviceable' => true, 'cod' => true, 'prepaid' => true,
            'pickup' => true, 'checked' => false, 'carrier' => null,
        ];
    }
}
