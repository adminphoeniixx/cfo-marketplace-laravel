<?php

namespace App\Http\Controllers\Api\Seller;

use App\Models\Product;
use App\Models\User;
use App\Notifications\ProductAwaitingPrice;
use App\Services\Notifier;

/**
 * A seller names what they want paid; the marketplace names the price.
 *
 * Shared by the seller panel and the seller API, so both doors apply the same
 * rules:
 *
 *  - A seller sends `cost_price` (and one per variant). `price` and
 *    `compare_at_price` are not theirs to set, and are left out of validation
 *    so a client still sending them is ignored rather than refused.
 *  - A seller can never make a product live. Asking for `active` sends it to
 *    `pending`, where staff set the price and publish it.
 *  - A live product stays live through an edit, unless the cost moved. Then
 *    the price it was sold at was worked out against a cost that no longer
 *    holds, so it comes off sale until staff look again.
 */
trait LeavesPricingToMarketplace
{
    /**
     * @return array<string, list<string>>
     */
    protected function sellerPricingRules(): array
    {
        return [
            // A variable product is costed per variant, like it is priced.
            'cost_price' => ['required_if:type,simple', 'nullable', 'numeric', 'min:0', 'max:99999999'],
            'variants.*.cost_price' => ['required', 'numeric', 'min:0', 'max:99999999'],
        ];
    }

    /**
     * The status a seller's save actually leaves the product in.
     *
     * @param  array<string, mixed>  $data  Whatever the request carried; an
     *                                      absent cost or variant list counts as unchanged.
     */
    protected function sellerStatus(string $requested, ?Product $product, array $data = []): string
    {
        if ($requested !== 'active') {
            return $requested;
        }

        return $product?->status === 'active' && ! $this->costChanged($product, $data)
            ? 'active'
            : 'pending';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function costChanged(Product $product, array $data): bool
    {
        if (array_key_exists('cost_price', $data) && ! self::sameMoney($product->cost_price, $data['cost_price'])) {
            return true;
        }

        if (! array_key_exists('variants', $data) || ($data['type'] ?? $product->type) !== 'variable') {
            return false;
        }

        $current = $product->variants()->pluck('cost_price', 'id');

        foreach ($data['variants'] as $variant) {
            $id = (int) ($variant['id'] ?? 0);

            // A variant the marketplace has never seen has never been priced.
            if (! $current->has($id) || ! self::sameMoney($current[$id], $variant['cost_price'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tell staff there is something to price, when this save is what put it
     * in the queue. Re-saving a product that is already waiting stays quiet.
     */
    protected function announceIfAwaitingPrice(Product $product, ?string $before, User $actor): void
    {
        if ($product->status !== 'pending' || $before === 'pending' || ! $product->vendor) {
            return;
        }

        Notifier::send(new ProductAwaitingPrice($product->vendor, 1, $product), $actor);
    }

    private static function sameMoney(mixed $a, mixed $b): bool
    {
        if ($a === null || $a === '' || $b === null || $b === '') {
            return ($a === null || $a === '') && ($b === null || $b === '');
        }

        return round((float) $a, 2) === round((float) $b, 2);
    }
}
