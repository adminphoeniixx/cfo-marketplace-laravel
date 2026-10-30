<?php

namespace App\Notifications;

use App\Models\Product;

/**
 * Staff priced a seller's product and put it on sale. Sent with
 * `Notifier::toStore()` — the price is the news, and it is the seller's.
 */
class ProductWentLive extends AdminNotification
{
    public function __construct(private readonly Product $product) {}

    public static function section(): string
    {
        return 'products';
    }

    public function title(): string
    {
        return "{$this->product->name} is live";
    }

    public function body(): string
    {
        return 'Shoppers now see it at ₹'.number_format((float) $this->product->price, 2).'.';
    }

    public function url(): string
    {
        return "/seller/products/{$this->product->id}/edit";
    }

    public function tone(): string
    {
        return 'success';
    }

    public function vendorId(): ?int
    {
        return $this->product->vendor_id;
    }
}
