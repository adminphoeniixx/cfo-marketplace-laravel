<?php

namespace App\Http\Resources\Customer;

use App\Models\Category;
use App\Services\BunnyCdn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Category
 */
class CategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'image' => BunnyCdn::display($this->image_path),
            'is_featured' => (bool) $this->is_featured,
            'products_count' => $this->when(isset($this->products_count), fn () => (int) $this->products_count),
            'children' => CategoryResource::collection($this->whenLoaded('children')),
        ];
    }
}
