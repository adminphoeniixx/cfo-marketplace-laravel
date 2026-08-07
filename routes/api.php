<?php

use App\Http\Controllers\Api\Seller\AnalyticsController;
use App\Http\Controllers\Api\Seller\AuthController;
use App\Http\Controllers\Api\Seller\CancellationController;
use App\Http\Controllers\Api\Seller\CatalogController;
use App\Http\Controllers\Api\Seller\DashboardController;
use App\Http\Controllers\Api\Seller\NotificationController;
use App\Http\Controllers\Api\Seller\OrderController;
use App\Http\Controllers\Api\Seller\PayoutController;
use App\Http\Controllers\Api\Seller\ProductController;
use App\Http\Controllers\Api\Seller\ProfileController;
use App\Http\Controllers\Api\Seller\RefundController;
use App\Http\Controllers\Api\Seller\ShippingController;
use App\Http\Controllers\Api\Seller\TeamController;
use App\Http\Controllers\Api\Seller\UploadController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Seller API
|--------------------------------------------------------------------------
|
| Token-authenticated (Sanctum) endpoints for the seller app. Three tiers:
|
|   public          — sign up, sign in, password reset
|   seller:any      — reachable while the store is still pending approval
|   seller          — trading endpoints, approved stores only
|
| Every list and lookup below is scoped to the token's own store by the
| `ScopesToStore` trait. No endpoint takes a vendor id from the caller.
|
*/

Route::prefix('seller')->name('api.seller.')->group(function () {

    /*
    | Public — rate limited hard, since these are the guessable ones.
    */
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->name('register');
        Route::post('login', [AuthController::class, 'login'])->name('login');
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->name('forgot-password');
        Route::post('reset-password', [AuthController::class, 'resetPassword'])->name('reset-password');
    });

    Route::middleware('auth:sanctum')->group(function () {

        /*
        | Reachable while pending approval: the app needs to sign the seller
        | out, show who they are, and show why they cannot trade yet.
        */
        Route::middleware('seller:any')->group(function () {
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::post('logout-all', [AuthController::class, 'logoutAll'])->name('logout-all');
            Route::get('devices', [AuthController::class, 'devices'])->name('devices');
            Route::delete('devices/{token}', [AuthController::class, 'revokeDevice'])->name('devices.revoke');

            Route::get('me', [ProfileController::class, 'show'])->name('me');
            Route::put('me', [ProfileController::class, 'update'])->name('me.update');
            Route::put('me/password', [ProfileController::class, 'updatePassword'])->name('me.password');

            Route::get('store', [ProfileController::class, 'store'])->name('store');
            Route::put('store', [ProfileController::class, 'updateStore'])->name('store.update');

            // Personal, not the store's — a pending seller still gets told
            // when their store is approved.
            Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
            Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread');
            Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
            Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
            Route::delete('notifications/{notification}', [NotificationController::class, 'destroy'])->name('notifications.destroy');

            Route::get('push', [NotificationController::class, 'pushSettings'])->name('push.settings');
            Route::post('push', [NotificationController::class, 'subscribe'])->name('push.subscribe');
            Route::delete('push', [NotificationController::class, 'unsubscribe'])->name('push.unsubscribe');
        });

        /*
        | Trading — approved stores only.
        */
        Route::middleware('seller')->group(function () {
            Route::get('dashboard', DashboardController::class)->name('dashboard');

            Route::post('uploads', [UploadController::class, 'store'])
                ->middleware('throttle:60,1')
                ->name('uploads.store');

            // Shared reference data for the product form.
            Route::get('catalog/options', [CatalogController::class, 'options'])->name('catalog.options');
            Route::get('catalog/categories', [CatalogController::class, 'categories'])->name('catalog.categories');
            Route::get('catalog/attributes', [CatalogController::class, 'attributes'])->name('catalog.attributes');
            Route::get('catalog/tax-classes', [CatalogController::class, 'taxClasses'])->name('catalog.tax-classes');

            Route::get('analytics/sales', [AnalyticsController::class, 'sales'])->name('analytics.sales');

            Route::get('team', [TeamController::class, 'index'])->name('team.index');
            Route::post('team', [TeamController::class, 'store'])->name('team.store');
            Route::put('team/{member}', [TeamController::class, 'update'])->name('team.update');
            Route::patch('team/{member}/toggle', [TeamController::class, 'toggle'])->name('team.toggle');
            Route::delete('team/{member}', [TeamController::class, 'destroy'])->name('team.destroy');

            Route::get('shipping/zones', [ShippingController::class, 'zones'])->name('shipping.zones');
            Route::get('shipping/rates', [ShippingController::class, 'index'])->name('shipping.rates.index');
            Route::post('shipping/rates', [ShippingController::class, 'store'])->name('shipping.rates.store');
            Route::put('shipping/rates/{rate}', [ShippingController::class, 'update'])->name('shipping.rates.update');
            Route::delete('shipping/rates/{rate}', [ShippingController::class, 'destroy'])->name('shipping.rates.destroy');

            Route::post('products/bulk', [ProductController::class, 'bulk'])->name('products.bulk');
            Route::get('products', [ProductController::class, 'index'])->name('products.index');
            Route::post('products', [ProductController::class, 'store'])->name('products.store');
            Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show');
            Route::put('products/{product}', [ProductController::class, 'update'])->name('products.update');
            Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
            Route::patch('products/{product}/status', [ProductController::class, 'updateStatus'])->name('products.status');
            Route::patch('products/{product}/stock', [ProductController::class, 'updateStock'])->name('products.stock');

            Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
            Route::get('orders/summary', [OrderController::class, 'summary'])->name('orders.summary');
            Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
            Route::post('orders/{order}/fulfill', [OrderController::class, 'fulfill'])->name('orders.fulfill');
            Route::post('orders/{order}/notes', [OrderController::class, 'addNote'])->name('orders.notes');

            Route::get('cancellations', [CancellationController::class, 'index'])->name('cancellations.index');
            Route::get('cancellations/{cancellation}', [CancellationController::class, 'show'])->name('cancellations.show');
            Route::post('cancellations/{cancellation}/respond', [CancellationController::class, 'respond'])->name('cancellations.respond');

            Route::get('refunds', [RefundController::class, 'index'])->name('refunds.index');
            Route::get('refunds/{refund}', [RefundController::class, 'show'])->name('refunds.show');
            Route::post('refunds/{refund}/respond', [RefundController::class, 'respond'])->name('refunds.respond');

            Route::get('payouts', [PayoutController::class, 'index'])->name('payouts.index');
            Route::get('payouts/earnings', [PayoutController::class, 'earnings'])->name('payouts.earnings');
            Route::get('payouts/{payout}', [PayoutController::class, 'show'])->name('payouts.show');
        });
    });
});
