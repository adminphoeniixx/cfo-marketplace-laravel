<?php

namespace App\Notifications;

use App\Models\Product;

class LowStockReached extends AdminNotification
{
    public function __construct(private readonly Product $product) {}

    public static function section(): string
    {
        return 'products';
    }

    public function title(): string
    {
        return "{$this->product->name} is running low";
    }

    public function body(): string
    {
        return "{$this->product->stock_quantity} left, threshold is {$this->product->low_stock_threshold}.";
    }

    public function url(): string
    {
        return "/admin/products/{$this->product->id}/edit";
    }

    public function tone(): string
    {
        return 'warning';
    }

    public function vendorId(): ?int
    {
        return $this->product->vendor_id;
    }
}
