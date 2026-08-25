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
| Trading endpoints carry a third gate, `seller.section`, which is the same
| role matrix the admin panel uses. Turning "Payouts" off for the vendor role
| closes the panel section and this API together, rather than leaving the app
| as a way around it. Account-level endpoints — profile, store, notifications,
| devices — are ungated: they belong to the person, not to a section.
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
            // Before the token ages out, not after — an expired token cannot
            // reach this, so the app must refresh while it still works.
            Route::post('refresh', [AuthController::class, 'refresh'])->name('refresh');

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

            // Firebase, for the phone app. Register after sign-in and again on
            // every token refresh; forget on sign-out.
            Route::post('push/device', [NotificationController::class, 'registerDevice'])->name('push.device.register');
            Route::delete('push/device', [NotificationController::class, 'forgetDevice'])->name('push.device.forget');
        });

        /*
        | Trading — approved stores only.
        */
        Route::middleware('seller')->group(function () {
            Route::get('dashboard', DashboardController::class)->name('dashboard');

            // Ungated: the store logo goes through here too, and that is part
            // of the account rather than the catalog.
            Route::post('uploads', [UploadController::class, 'store'])
                ->middleware('throttle:60,1')
                ->name('uploads.store');

            // Shared reference data for the product form, so it stands or
            // falls with the products section.
            Route::middleware('seller.section:products')->group(function () {
                Route::get('catalog/options', [CatalogController::class, 'options'])->name('catalog.options');
                Route::get('catalog/categories', [CatalogController::class, 'categories'])->name('catalog.categories');
                Route::get('catalog/attributes', [CatalogController::class, 'attributes'])->name('catalog.attributes');
                Route::get('catalog/tax-classes', [CatalogController::class, 'taxClasses'])->name('catalog.tax-classes');
            });

            Route::middleware('seller.section:analytics')->group(function () {
                Route::get('analytics/sales', [AnalyticsController::class, 'sales'])->name('analytics.sales');

                // The full report the seller panel renders, and the same CSV
                // exports — both narrowed to this store by `resolveVendor()`.
                Route::get('analytics/report', [AnalyticsController::class, 'report'])->name('analytics.report');
                Route::get('analytics/export/{report}', [AnalyticsController::class, 'export'])->name('analytics.export');
            });

            Route::middleware('seller.section:team')->group(function () {
                Route::get('team', [TeamController::class, 'index'])->name('team.index');
                Route::post('team', [TeamController::class, 'store'])->name('team.store');
                Route::put('team/{member}', [TeamController::class, 'update'])->name('team.update');
                Route::patch('team/{member}/toggle', [TeamController::class, 'toggle'])->name('team.toggle');
                Route::delete('team/{member}', [TeamController::class, 'destroy'])->name('team.destroy');
            });

            Route::middleware('seller.section:shipping')->group(function () {
                Route::get('shipping/zones', [ShippingController::class, 'zones'])->name('shipping.zones');
                Route::get('shipping/rates', [ShippingController::class, 'index'])->name('shipping.rates.index');
                Route::post('shipping/rates', [ShippingController::class, 'store'])->name('shipping.rates.store');
                Route::put('shipping/rates/{rate}', [ShippingController::class, 'update'])->name('shipping.rates.update');
                Route::delete('shipping/rates/{rate}', [ShippingController::class, 'destroy'])->name('shipping.rates.destroy');
            });

            // The carrier picker on the pack-and-ship screen, so it follows
            // orders rather than the shipping-rates section.
            Route::middleware('seller.section:orders')
                ->get('delivery-partners', [ShippingController::class, 'deliveryPartners'])
                ->name('delivery-partners');

            Route::middleware('seller.section:products')->group(function () {
                Route::post('products/bulk', [ProductController::class, 'bulk'])->name('products.bulk');
                Route::get('products', [ProductController::class, 'index'])->name('products.index');
                Route::post('products', [ProductController::class, 'store'])->name('products.store');
                Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show');
                Route::put('products/{product}', [ProductController::class, 'update'])->name('products.update');
                Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
                Route::patch('products/{product}/status', [ProductController::class, 'updateStatus'])->name('products.status');
                Route::patch('products/{product}/stock', [ProductController::class, 'updateStock'])->name('products.stock');
            });

            Route::middleware('seller.section:orders')->group(function () {
                Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
                Route::get('orders/summary', [OrderController::class, 'summary'])->name('orders.summary');

                /*
                | Manual order entry — a phone order, a repeat customer, a fix
                | for a checkout that went wrong. The store comes from the
                | token, never the payload. These three sit above the {order}
                | route so their paths are not read as ids.
                */
                Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
                Route::get('orders/sellable', [OrderController::class, 'sellable'])->name('orders.sellable');
                Route::get('orders/customers', [OrderController::class, 'customers'])->name('orders.customers');

                Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
                Route::post('orders/{order}/fulfill', [OrderController::class, 'fulfill'])->name('orders.fulfill');
                Route::post('orders/{order}/notes', [OrderController::class, 'addNote'])->name('orders.notes');
            });

            Route::middleware('seller.section:cancellations')->group(function () {
                Route::get('cancellations', [CancellationController::class, 'index'])->name('cancellations.index');
                Route::post('cancellations', [CancellationController::class, 'store'])->name('cancellations.store');
                Route::get('cancellations/{cancellation}', [CancellationController::class, 'show'])->name('cancellations.show');
                Route::post('cancellations/{cancellation}/respond', [CancellationController::class, 'respond'])->name('cancellations.respond');
            });

            Route::middleware('seller.section:refunds')->group(function () {
                Route::get('refunds', [RefundController::class, 'index'])->name('refunds.index');
                Route::get('refunds/{refund}', [RefundController::class, 'show'])->name('refunds.show');
                Route::post('refunds/{refund}/respond', [RefundController::class, 'respond'])->name('refunds.respond');
            });

            Route::middleware('seller.section:payouts')->group(function () {
                Route::get('payouts', [PayoutController::class, 'index'])->name('payouts.index');
                Route::get('payouts/earnings', [PayoutController::class, 'earnings'])->name('payouts.earnings');
                Route::get('payouts/{payout}', [PayoutController::class, 'show'])->name('payouts.show');
            });
        });
    });
});
