<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SearchController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $term = trim($request->string('q')->toString());

        return Inertia::render('admin/Search', [
            'term' => $term,
            'results' => $term === '' ? null : [
                'orders' => Order::with('customer:id,first_name,last_name')
                    ->where(fn ($query) => $query->where('number', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%"))
                    ->limit(10)
                    ->get(['id', 'number', 'customer_id', 'status', 'grand_total', 'placed_at']),
                'products' => Product::where(fn ($query) => $query->where('name', 'like', "%{$term}%")->orWhere('sku', 'like', "%{$term}%"))
                    ->limit(10)
                    ->get(['id', 'name', 'sku', 'price', 'status', 'stock_quantity']),
                'customers' => Customer::where(fn ($query) => $query->where('first_name', 'like', "%{$term}%")
                    ->orWhere('last_name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%"))
                    ->limit(10)
                    ->get(['id', 'first_name', 'last_name', 'email', 'total_spent', 'orders_count']),
                'vendors' => Vendor::where('name', 'like', "%{$term}%")
                    ->limit(10)
                    ->get(['id', 'name', 'status', 'commission_rate']),
            ],
        ]);
    }
}
