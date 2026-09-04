<?php

namespace App\Actions\Customer;

use App\Actions\CreateManualOrder;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\CustomerAddress;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Services\Eta;
use Illuminate\Support\Collection;

/**
 * What a basket costs.
 *
 * One place answers that question for the cart screen, the checkout screen and
 * the order that finally gets written — otherwise the total moves as the
 * shopper walks through the funnel, which is the one thing a checkout may
 * never do. Tax and commission come from the same helpers the manual-order
 * action uses, so an order raised by a seller and one placed by a shopper
 * price identically.
 */
class QuoteBasket
{
    /**
     * @param  Collection<int, CartItem>  $items  Active lines only.
     * @return array<string, mixed>
     */
    public function handle(
        Collection $items,
        ?Coupon $coupon = null,
        ?CustomerAddress $address = null,
        ?string $shippingCode = null,
    ): array {
        $lines = $items->map(fn (CartItem $item) => $this->line($item))->values();

        $subtotal = round($lines->sum('total'), 2);
        $mrpTotal = round($lines->sum('mrp_total'), 2);

        $discount = $coupon ? $coupon->discountOn($subtotal) : 0.0;

        $shippingOptions = $this->shippingOptions($lines, $subtotal, $address, $coupon);
        $chosen = collect($shippingOptions)->firstWhere('code', $shippingCode) ?? ($shippingOptions[0] ?? null);
        $shipping = (float) ($chosen['rate'] ?? 0);
        // What delivery would have cost with no coupon on the basket. A
        // free-shipping code on a basket that already ships free is worth
        // nothing, and that has to be visible rather than left as a total the
        // shopper watches not move.
        $shippingFull = (float) ($chosen['full_rate'] ?? $shipping);
        $shippingDiscount = round(max($shippingFull - $shipping, 0), 2);

        // Tax follows the discount: the shopper is taxed on what they pay, so
        // the coupon comes off each line in proportion before the rate applies.
        $taxable = max($subtotal - $discount, 0);
        $ratio = $subtotal > 0 ? $taxable / $subtotal : 0;
        $tax = round($lines->sum(fn (array $l) => $l['total'] * $ratio * $l['tax_rate'] / 100), 2);

        return [
            'lines' => $lines->all(),
            'groups' => $this->groupByVendor($lines),
            'coupon' => $coupon ? [
                'code' => $coupon->code,
                'description' => $coupon->description,
                'discount' => $discount,
                'shipping_discount' => $shippingDiscount,
                // The whole of what this code is worth here — items and
                // delivery together. Zero means the basket is no cheaper for
                // having it, which is a thing worth saying out loud.
                'savings' => round($discount + $shippingDiscount, 2),
                'covers_shipping' => $coupon->coversShipping($subtotal),
            ] : null,
            'shipping_options' => $shippingOptions,
            'shipping_method' => $chosen['name'] ?? null,
            'shipping_code' => $chosen['code'] ?? null,
            'totals' => [
                'items_count' => (int) $lines->sum('quantity'),
                'mrp_total' => $mrpTotal,
                'subtotal' => $subtotal,
                'saved_on_mrp' => round(max($mrpTotal - $subtotal, 0), 2),
                'discount_total' => $discount,
                'shipping_total' => $shipping,
                'shipping_full_total' => $shippingFull,
                'tax_total' => $tax,
                'grand_total' => round($taxable + $shipping + $tax, 2),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function line(CartItem $item): array
    {
        $product = $item->product;
        // Reading it off the column rather than the relation: a simple product
        // has no variant, and that is what makes the null real to the reader
        // as well as to the type checker.
        $variant = $item->product_variant_id ? $item->variant : null;

        $unit = $item->unitPrice();
        $quantity = (int) $item->quantity;
        // `->` rather than `?->`: on the left of `??` PHP already reads the
        // property with isset semantics, so a null variant falls through.
        $mrp = (float) ($variant->compare_at_price ?? $product->compare_at_price ?? $unit);
        $available = $item->availableStock();

        return [
            'cart_item_id' => $item->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'vendor_id' => $product->vendor_id,
            'name' => $product->name,
            'sku' => $variant->sku ?? $product->sku,
            'options' => $variant?->name ? ['Variant' => $variant->name] : null,
            'image' => $product->images->first()?->path,
            // Drawn where there is no photograph. Worked out here so the cart
            // screen and the checkout summary show the same tile.
            'emoji' => $product->emoji(),
            'unit_price' => $unit,
            'mrp' => max($mrp, $unit),
            'quantity' => $quantity,
            'total' => round($unit * $quantity, 2),
            'mrp_total' => round(max($mrp, $unit) * $quantity, 2),
            'tax_rate' => CreateManualOrder::taxRateFor($product),
            'weight' => (float) ($variant->weight ?? $product->weight),
            // The cart screen has to be able to say "only 2 left" before the
            // shopper reaches payment and gets refused.
            'in_stock' => $available >= $quantity,
            'available' => $available === PHP_INT_MAX ? null : $available,
        ];
    }

    /**
     * Sellers on this basket, in the order their first line appears.
     *
     * A basket spanning two stores is normal here, so nothing downstream may
     * treat the first line's vendor as "the" vendor of the order.
     *
     * @param  Collection<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    protected function groupByVendor(Collection $lines): array
    {
        return $lines
            ->groupBy('vendor_id')
            ->map(fn (Collection $group, $vendorId) => [
                'vendor_id' => (int) $vendorId,
                'items_count' => (int) $group->sum('quantity'),
                'subtotal' => round($group->sum('total'), 2),
                'cart_item_ids' => $group->pluck('cart_item_id')->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Delivery choices for this basket and this address.
     *
     * Rates are the seller's own where they have set any for the zone, and the
     * marketplace's (`vendor_id` null) where they have not. A basket touching
     * two stores pays the dearer of the two for that option — the parcels ship
     * separately, but the shopper is quoted one delivery line.
     *
     * @param  Collection<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    protected function shippingOptions(
        Collection $lines,
        float $subtotal,
        ?CustomerAddress $address,
        ?Coupon $coupon,
    ): array {
        if ($lines->isEmpty()) {
            return [];
        }

        $zone = $this->zoneFor($address);
        $vendorIds = $lines->pluck('vendor_id')->filter()->unique()->all();

        $rates = $zone
            ? ShippingRate::query()
                ->where('is_active', true)
                ->where('shipping_zone_id', $zone->id)
                // Either a seller's own rate for a store on this basket, or the
                // marketplace's rate for the zone. Never another store's.
                ->where(fn ($query) => $query
                    ->whereNull('vendor_id')
                    ->orWhereIn('vendor_id', $vendorIds))
                // The panel collects a basket-value band per rate — "₹0–₹499
                // goes by Standard" — and quoting ignored it, so a rate meant
                // for small baskets was offered on every one of them.
                ->where(fn ($query) => $query
                    ->whereNull('min_order_amount')
                    ->orWhere('min_order_amount', '<=', $subtotal))
                ->where(fn ($query) => $query
                    ->whereNull('max_order_amount')
                    ->orWhere('max_order_amount', '>=', $subtotal))
                ->orderBy('position')
                ->get()
            : collect();

        $freeBecause = ($coupon?->coversShipping($subtotal) ?? false) ? 'coupon' : null;

        $options = $rates
            ->groupBy('name')
            ->map(function (Collection $group) use ($lines, $subtotal, $freeBecause, $coupon) {
                $cost = $group->max(fn (ShippingRate $rate) => $this->costOf($rate, $lines, $subtotal));
                $rate = $group->first();

                $charged = $freeBecause !== null ? 0.0 : round((float) $cost, 2);
                // The highest threshold on the group: on a basket touching two
                // stores, delivery is only free once every one of them is.
                $freeAbove = $group->pluck('free_above_amount')->filter()->max();

                return [
                    'code' => str($rate->name)->slug()->value(),
                    'name' => (string) $rate->name,
                    'rate' => $charged,
                    // Before any coupon, so what a free-shipping code saves is
                    // arithmetic rather than a guess.
                    'full_rate' => round((float) $cost, 2),
                    'free_because' => $freeBecause,
                    'delivery_days_min' => $rate->delivery_days_min,
                    'delivery_days_max' => $rate->delivery_days_max,
                    ...self::labelsFor(
                        $charged,
                        $rate->delivery_days_min,
                        $rate->delivery_days_max,
                        $freeBecause === 'coupon' ? $coupon->code : null,
                        $freeAbove !== null ? (float) $freeAbove : null,
                    ),
                ];
            })
            ->values()
            ->all();

        if ($options !== []) {
            return $options;
        }

        // Nothing configured for this address? The marketplace still has to be
        // able to take the order, so free standard delivery is the fallback.
        return [[
            'code' => 'standard',
            'name' => 'Standard delivery',
            'rate' => 0.0,
            'full_rate' => 0.0,
            'free_because' => $freeBecause,
            'delivery_days_min' => 3,
            'delivery_days_max' => 7,
            ...self::labelsFor(0.0, 3, 7, $freeBecause === 'coupon' ? $coupon->code : null, null),
        ]];
    }

    /**
     * The three strings the delivery row actually draws.
     *
     * Display-ready on purpose: the app was turning day counts into "Arrives
     * 4–6 Sep" itself and falling back to a hardcoded line where a rate had no
     * days on it, so the same option could read differently on two screens.
     *
     * @return array{price_label: string, eta_label: string|null, note: string|null}
     */
    protected static function labelsFor(
        float $rate,
        ?int $minDays,
        ?int $maxDays,
        ?string $couponCode,
        ?float $freeAbove,
    ): array {
        $window = Eta::window($minDays, $maxDays);

        return [
            'price_label' => $rate <= 0 ? 'FREE' : '₹'.number_format($rate, fmod($rate, 1) === 0.0 ? 0 : 2),
            'eta_label' => $window ? Eta::label($window[0], $window[1]) : null,
            'note' => match (true) {
                $couponCode !== null => "Free with {$couponCode}",
                $freeAbove !== null && $rate > 0 => 'Free over ₹'.number_format($freeAbove, 0),
                default => null,
            },
        ];
    }

    protected function zoneFor(?CustomerAddress $address): ?ShippingZone
    {
        if (! $address) {
            return null;
        }

        return ShippingZone::query()
            ->where('is_active', true)
            ->orderBy('position')
            ->get()
            ->first(function (ShippingZone $zone) use ($address) {
                $states = collect((array) $zone->states)->map(fn ($state) => mb_strtolower((string) $state));
                $postcodes = collect((array) $zone->postcodes)->map(fn ($code) => (string) $code);
                $countries = collect((array) $zone->countries)->map(fn ($country) => mb_strtoupper((string) $country));

                if ($states->isNotEmpty() && $states->contains(mb_strtolower((string) $address->state))) {
                    return true;
                }

                if ($postcodes->isNotEmpty() && $postcodes->contains((string) $address->postcode)) {
                    return true;
                }

                // A zone naming only a country is the catch-all for that country.
                return $states->isEmpty()
                    && $postcodes->isEmpty()
                    && $countries->contains(mb_strtoupper((string) ($address->country ?? 'IN')));
            });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lines
     */
    protected function costOf(ShippingRate $rate, Collection $lines, float $subtotal): float
    {
        if ($rate->free_above_amount !== null && $subtotal >= (float) $rate->free_above_amount) {
            return 0.0;
        }

        $quantity = (int) $lines->sum('quantity');
        $weight = (float) $lines->sum(fn (array $l) => $l['weight'] * $l['quantity']);

        /*
        | The arms are `ShippingRate::TYPES` and the sums are the ones the admin
        | panel's own rate label promises. They used to be `per_item` and
        | `weight` — two spellings nothing has ever written — so every rate fell
        | through to a default that added all three columns together. Panel-made
        | rates came out right only because the columns it hides are left at
        | zero; one hand-edited row would have been charged twice over.
        */
        return round(match ($rate->type) {
            'free' => 0.0,
            'weight_based' => (float) $rate->rate + (float) $rate->per_kg_rate * $weight,
            'item_based' => (float) $rate->rate + (float) $rate->per_item_rate * $quantity,
            default => (float) $rate->rate,
        }, 2);
    }
}
