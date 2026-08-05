<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use App\Models\TaxClass;
use Illuminate\Http\JsonResponse;

/**
 * The read-only reference lists a seller needs to fill in a product form.
 * Shared marketplace data, identical for every store.
 */
class CatalogController extends Controller
{
    public function categories(): JsonResponse
    {
        return response()->json([
            'data' => Category::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'parent_id']),
        ]);
    }

    public function attributes(): JsonResponse
    {
        return response()->json([
            'data' => Attribute::with('values:id,attribute_id,value,color_hex')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'type', 'is_variant']),
        ]);
    }

    public function taxClasses(): JsonResponse
    {
        return response()->json([
            'data' => TaxClass::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    /**
     * Everything the product form needs in one round trip.
     */
    public function options(): JsonResponse
    {
        return response()->json([
            'categories' => Category::where('is_active', true)->orderBy('name')->get(['id', 'name', 'parent_id']),
            'attributes' => Attribute::with('values:id,attribute_id,value,color_hex')
                ->where('is_active', true)->orderBy('name')->get(['id', 'name', 'type', 'is_variant']),
            'tax_classes' => TaxClass::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'statuses' => Product::STATUSES,
        ]);
    }
}
