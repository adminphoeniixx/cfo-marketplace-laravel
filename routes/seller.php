<?php

use App\Http\Controllers\Api\Seller\UploadController;
use App\Http\Controllers\Seller\AnalyticsController;
use App\Http\Controllers\Seller\ManualOrderController;
use App\Http\Controllers\Seller\NotificationController;
use App\Http\Controllers\Seller\OrderController;
use App\Http\Controllers\Seller\ProductController;
use App\Http\Controllers\Seller\StoreController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Seller panel
|--------------------------------------------------------------------------
|
| The web half of the seller app. Same store, same role matrix and the same
| `ScopesToStore` scoping the token API uses — only the transport differs, so
| a seller can work from a browser or the phone app and see one catalog.
|
| Deliberately *not* folded into routes/admin.php: those screens read the whole
| marketplace. Keeping the seller on its own prefix means a new admin filter or
| column can never leak here by accident — which is exactly how a vendor came
| to see every marketplace order through `admin.orders.index`.
|
| Account-level screens — notifications, store details — are ungated, matching
| the API: they belong to the person and their store, not to a section.
|
*/

Route::middleware(['auth', 'verified', 'seller.panel'])
    ->prefix('seller')
    ->name('seller.')
    ->group(function () {
        Route::redirect('/', '/seller/orders')->name('home');

        // Reused from the token API as-is: it takes the store from the signed-in
        // user and files every upload under that store's own folder.
        Route::post('uploads', [UploadController::class, 'store'])->name('uploads.store');

        /*
        | Notifications — the signed-in user's own pile.
        */
        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::delete('notifications/read', [NotificationController::class, 'clearRead'])->name('notifications.clear-read');
        Route::post('notifications/push', [NotificationController::class, 'subscribe'])->name('notifications.push.subscribe');
        Route::delete('notifications/push', [NotificationController::class, 'unsubscribe'])->name('notifications.push.unsubscribe');
        Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
        Route::delete('notifications/{notification}', [NotificationController::class, 'destroy'])->name('notifications.destroy');

        /*
        | Store details and payout account.
        */
        Route::get('settings/store', [StoreController::class, 'edit'])->name('settings.store');
        Route::put('settings/store', [StoreController::class, 'update'])->name('settings.store.update');

        /*
        | Catalog.
        */
        Route::middleware('seller.panel:products')->group(function () {
            Route::post('products/bulk', [ProductController::class, 'bulk'])->name('products.bulk');
            Route::resource('products', ProductController::class);
        });

        /*
        | Orders. A seller packs their own lines and leaves notes; the order's
        | status and payment state stay the marketplace's call.
        */
        Route::middleware('seller.panel:orders')->group(function () {
            Route::get('orders', [OrderController::class, 'index'])->name('orders.index');

            // Manual entry, before the {order} route so "create" is not read
            // as an id. Served by the marketplace controller, which already
            // locks a vendor to their own store.
            Route::get('orders/create', [ManualOrderController::class, 'create'])->name('orders.create');
            Route::post('orders', [ManualOrderController::class, 'store'])->name('orders.store');

            Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
            Route::post('orders/{order}/fulfill', [OrderController::class, 'fulfill'])->name('orders.fulfill');
            Route::post('orders/{order}/notes', [OrderController::class, 'addNote'])->name('orders.notes');
        });

        /*
        | Analytics.
        */
        Route::middleware('seller.panel:analytics')->group(function () {
            Route::get('analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
            Route::get('analytics/export/{report}', [AnalyticsController::class, 'export'])->name('analytics.export');
        });
    });
