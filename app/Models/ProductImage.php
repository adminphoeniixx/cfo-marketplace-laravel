<?php

namespace App\Models;

use App\Services\BunnyCdn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    protected $guarded = [];

    /** @var list<string> */
    protected $appends = ['url'];

    /**
     * Renderable URL for the stored path — signed when the pull zone uses
     * token authentication.
     */
    public function getUrlAttribute(): ?string
    {
        return BunnyCdn::display($this->path);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
