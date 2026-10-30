<?php

namespace App\Notifications;

use App\Models\Product;
use App\Models\Vendor;

/**
 * A seller has sent the marketplace something to price. Staff only: the
 * seller already knows, having just pressed the button.
 */
class ProductAwaitingPrice extends AdminNotification
{
    /**
     * @param  Product|null  $product  The one product, when only one was sent.
     */
    public function __construct(
        private readonly Vendor $vendor,
        private readonly int $count = 1,
        private readonly ?Product $product = null,
    ) {}

    public static function section(): string
    {
        return 'products';
    }

    public function title(): string
    {
        return $this->product && $this->count === 1
            ? "{$this->product->name} needs a price"
            : "{$this->count} products from {$this->vendor->name} need a price";
    }

    public function body(): string
    {
        $cost = $this->product?->cost_price;

        return $this->product && $this->count === 1 && $cost !== null
            ? "{$this->vendor->name} · cost ₹".number_format((float) $cost, 2)
            : "Sent by {$this->vendor->name}. Hidden from shoppers until priced.";
    }

    public function url(): string
    {
        return $this->product && $this->count === 1
            ? "/admin/products/{$this->product->id}/edit"
            : "/admin/products?status=pending&vendor={$this->vendor->id}";
    }

    public function tone(): string
    {
        return 'attention';
    }
}
