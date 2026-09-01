<?php

namespace App\Http\Resources\Customer;

use App\Models\Cart;
use App\Models\Vendor;
use App\Services\BunnyCdn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The basket, priced.
 *
 * The quote is passed in rather than worked out here: one class decides what a
 * basket costs, and a resource is not the place for arithmetic that also has
 * to hold at checkout.
 *
 * @mixin Cart
 */
class CartResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $quote
     */
    public function __construct(Cart $cart, protected array $quote)
    {
        parent::__construct($cart);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $lines = (array) ($this->quote['lines'] ?? []);
        $groups = (array) ($this->quote['groups'] ?? []);

        $vendors = Vendor::whereIn('id', collect($lines)->pluck('vendor_id')->filter()->unique())
            ->get(['id', 'name', 'city'])
            ->keyBy('id');

        $saved = $this->items->where('saved_for_later', true);

        return [
            'id' => $this->id,
            'coupon' => $this->quote['coupon'],
            'totals' => $this->quote['totals'],
            'shipping_options' => $this->quote['shipping_options'],
            // Grouped the way the screen draws it: one block per seller,
            // because a basket across two stores ships as two parcels.
            'groups' => collect($groups)->map(fn (array $group) => [
                'vendor' => [
                    'id' => $group['vendor_id'],
                    'name' => $vendors[$group['vendor_id']]->name ?? 'Seller',
                    'city' => $vendors[$group['vendor_id']]->city ?? null,
                ],
                'items_count' => $group['items_count'],
                'subtotal' => $group['subtotal'],
                'items' => collect($lines)
                    ->whereIn('cart_item_id', $group['cart_item_ids'])
                    ->map(fn (array $line) => $this->line($line))
                    ->values(),
            ])->values(),
            'saved_for_later' => $saved->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'name' => $item->product->name,
                'image' => BunnyCdn::display($item->product->images->first()?->path),
                'price' => $item->unitPrice(),
                'quantity' => (int) $item->quantity,
            ])->values(),
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    protected function line(array $line): array
    {
        return [
            'id' => $line['cart_item_id'],
            'product_id' => $line['product_id'],
            'product_variant_id' => $line['product_variant_id'],
            'name' => $line['name'],
            'sku' => $line['sku'],
            'options' => $line['options'],
            'image' => BunnyCdn::display($line['image']),
            'unit_price' => $line['unit_price'],
            'mrp' => $line['mrp'],
            'quantity' => $line['quantity'],
            'total' => $line['total'],
            'in_stock' => $line['in_stock'],
            'available' => $line['available'],
        ];
    }
}
