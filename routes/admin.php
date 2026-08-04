<?php

use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\AttributeController;
use App\Http\Controllers\Admin\CancellationController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CustomerAddressController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\PayoutController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\RefundController;
use App\Http\Controllers\Admin\SearchController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\ShippingController;
use App\Http\Controllers\Admin\TaxController;
use App\Http\Controllers\Admin\VendorController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('search', SearchController::class)->name('search');
    Route::get('analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
    Route::get('analytics/export/{report}', [AnalyticsController::class, 'export'])->name('analytics.export');

    /*
    |--------------------------------------------------------------------------
    | Catalog
    |--------------------------------------------------------------------------
    */
    Route::resource('categories', CategoryController::class)->except('show');
    Route::resource('attributes', AttributeController::class)->except('show');

    Route::post('products/bulk', [ProductController::class, 'bulk'])->name('products.bulk');
    Route::resource('products', ProductController::class);

    /*
    |--------------------------------------------------------------------------
    | Customers
    |--------------------------------------------------------------------------
    */
    Route::patch('customers/{customer}/toggle-status', [CustomerController::class, 'toggleStatus'])
        ->name('customers.toggle-status');
    Route::resource('customers', CustomerController::class);
    Route::post('customers/{customer}/addresses', [CustomerAddressController::class, 'store'])
        ->name('customers.addresses.store');
    Route::put('customers/{customer}/addresses/{address}', [CustomerAddressController::class, 'update'])
        ->name('customers.addresses.update');
    Route::delete('customers/{customer}/addresses/{address}', [CustomerAddressController::class, 'destroy'])
        ->name('customers.addresses.destroy');

    /*
    |--------------------------------------------------------------------------
    | Orders
    |--------------------------------------------------------------------------
    */
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/create', [OrderController::class, 'create'])->name('orders.create');
    Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
    Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::put('orders/{order}', [OrderController::class, 'update'])->name('orders.update');
    Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.status');
    Route::patch('orders/{order}/payment', [OrderController::class, 'updatePayment'])->name('orders.payment');
    Route::post('orders/{order}/fulfill', [OrderController::class, 'fulfill'])->name('orders.fulfill');
    Route::post('orders/{order}/notes', [OrderController::class, 'addNote'])->name('orders.notes');

    /*
    |--------------------------------------------------------------------------
    | Cancellations
    |--------------------------------------------------------------------------
    */
    Route::get('cancellations', [CancellationController::class, 'index'])->name('cancellations.index');
    Route::get('cancellations/create', [CancellationController::class, 'create'])->name('cancellations.create');
    Route::post('cancellations', [CancellationController::class, 'store'])->name('cancellations.store');
    Route::get('cancellations/{cancellation}', [CancellationController::class, 'show'])->name('cancellations.show');
    Route::patch('cancellations/{cancellation}/approve', [CancellationController::class, 'approve'])
        ->name('cancellations.approve');
    Route::patch('cancellations/{cancellation}/reject', [CancellationController::class, 'reject'])
        ->name('cancellations.reject');

    /*
    |--------------------------------------------------------------------------
    | Refunds
    |--------------------------------------------------------------------------
    */
    Route::get('refunds', [RefundController::class, 'index'])->name('refunds.index');
    Route::get('refunds/create', [RefundController::class, 'create'])->name('refunds.create');
    Route::post('refunds', [RefundController::class, 'store'])->name('refunds.store');
    Route::get('refunds/{refund}', [RefundController::class, 'show'])->name('refunds.show');
    Route::patch('refunds/{refund}/approve', [RefundController::class, 'approve'])->name('refunds.approve');
    Route::patch('refunds/{refund}/reject', [RefundController::class, 'reject'])->name('refunds.reject');
    Route::patch('refunds/{refund}/process', [RefundController::class, 'process'])->name('refunds.process');

    /*
    |--------------------------------------------------------------------------
    | Marketplace
    |--------------------------------------------------------------------------
    */
    Route::patch('vendors/{vendor}/status', [VendorController::class, 'updateStatus'])->name('vendors.status');
    Route::resource('vendors', VendorController::class);

    Route::get('payouts', [PayoutController::class, 'index'])->name('payouts.index');
    Route::post('payouts', [PayoutController::class, 'store'])->name('payouts.store');
    Route::patch('payouts/{payout}/status', [PayoutController::class, 'updateStatus'])->name('payouts.status');
    Route::delete('payouts/{payout}', [PayoutController::class, 'destroy'])->name('payouts.destroy');

    /*
    |--------------------------------------------------------------------------
    | Taxes
    |--------------------------------------------------------------------------
    */
    Route::get('taxes', [TaxController::class, 'index'])->name('taxes.index');
    Route::post('taxes/classes', [TaxController::class, 'storeClass'])->name('taxes.classes.store');
    Route::put('taxes/classes/{taxClass}', [TaxController::class, 'updateClass'])->name('taxes.classes.update');
    Route::delete('taxes/classes/{taxClass}', [TaxController::class, 'destroyClass'])->name('taxes.classes.destroy');
    Route::post('taxes/rates', [TaxController::class, 'storeRate'])->name('taxes.rates.store');
    Route::put('taxes/rates/{taxRate}', [TaxController::class, 'updateRate'])->name('taxes.rates.update');
    Route::delete('taxes/rates/{taxRate}', [TaxController::class, 'destroyRate'])->name('taxes.rates.destroy');

    /*
    |--------------------------------------------------------------------------
    | Shipping
    |--------------------------------------------------------------------------
    */
    Route::get('shipping', [ShippingController::class, 'index'])->name('shipping.index');
    Route::post('shipping/zones', [ShippingController::class, 'storeZone'])->name('shipping.zones.store');
    Route::put('shipping/zones/{shippingZone}', [ShippingController::class, 'updateZone'])->name('shipping.zones.update');
    Route::delete('shipping/zones/{shippingZone}', [ShippingController::class, 'destroyZone'])->name('shipping.zones.destroy');
    Route::post('shipping/rates', [ShippingController::class, 'storeRate'])->name('shipping.rates.store');
    Route::put('shipping/rates/{shippingRate}', [ShippingController::class, 'updateRate'])->name('shipping.rates.update');
    Route::delete('shipping/rates/{shippingRate}', [ShippingController::class, 'destroyRate'])->name('shipping.rates.destroy');

    /*
    |--------------------------------------------------------------------------
    | Store settings
    |--------------------------------------------------------------------------
    */
    Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
    Route::put('settings', [SettingController::class, 'update'])->name('settings.update');
});
