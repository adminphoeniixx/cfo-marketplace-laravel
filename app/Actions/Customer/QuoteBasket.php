<?php

namespace App\Actions\Customer;

use App\Actions\CreateManualOrder;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\CustomerAddress;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
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
                ->orderBy('position')
                ->get()
            : collect();

        $freeBecause = ($coupon?->coversShipping($subtotal) ?? false) ? 'coupon' : null;

        $options = $rates
            ->groupBy('name')
            ->map(function (Collection $group) use ($lines, $subtotal, $freeBecause) {
                $cost = $group->max(fn (ShippingRate $rate) => $this->costOf($rate, $lines, $subtotal));
                $rate = $group->first();

                return [
                    'code' => str($rate->name)->slug()->value(),
                    'name' => (string) $rate->name,
                    'rate' => $freeBecause !== null ? 0.0 : round((float) $cost, 2),
                    'free_because' => $freeBecause,
                    'delivery_days_min' => $rate->delivery_days_min,
                    'delivery_days_max' => $rate->delivery_days_max,
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
            'free_because' => $freeBecause,
            'delivery_days_min' => 3,
            'delivery_days_max' => 7,
        ]];
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

        return round(match ($rate->type) {
            'free' => 0.0,
            'per_item' => (float) $rate->per_item_rate * $quantity,
            'weight' => (float) $rate->per_kg_rate * $weight,
            default => (float) $rate->rate
                + (float) $rate->per_item_rate * $quantity
                + (float) $rate->per_kg_rate * $weight,
        }, 2);
    }
}
