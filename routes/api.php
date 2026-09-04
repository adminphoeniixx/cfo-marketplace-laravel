<?php

use App\Http\Controllers\Api\Customer\AddressController as CustomerAddressController;
use App\Http\Controllers\Api\Customer\AuthController as CustomerAuthController;
use App\Http\Controllers\Api\Customer\CartController;
use App\Http\Controllers\Api\Customer\CatalogController as CustomerCatalogController;
use App\Http\Controllers\Api\Customer\CheckoutController;
use App\Http\Controllers\Api\Customer\ContentController;
use App\Http\Controllers\Api\Customer\InvoiceController;
use App\Http\Controllers\Api\Customer\NotificationController as CustomerNotificationController;
use App\Http\Controllers\Api\Customer\OrderController as CustomerOrderController;
use App\Http\Controllers\Api\Customer\PaymentController;
use App\Http\Controllers\Api\Customer\ProfileController as CustomerProfileController;
use App\Http\Controllers\Api\Customer\ReferenceController;
use App\Http\Controllers\Api\Customer\RequestController;
use App\Http\Controllers\Api\Customer\ReviewController;
use App\Http\Controllers\Api\Customer\WalletController;
use App\Http\Controllers\Api\Customer\WishlistController;
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
            // Notification ids are uuids. Without this a malformed id reaches
            // Postgres and comes back a 500 instead of a 404.
            Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])
                ->whereUuid('notification')->name('notifications.read');
            Route::delete('notifications/{notification}', [NotificationController::class, 'destroy'])
                ->whereUuid('notification')->name('notifications.destroy');

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

/*
|--------------------------------------------------------------------------
| Customer API
|--------------------------------------------------------------------------
|
| Token-authenticated (Sanctum) endpoints for the shopper app. Three tiers:
|
|   public          — browsing, and getting in
|   auth:sanctum    — nothing on its own; every route below adds `customer`
|   customer        — an active shopper, proven by the token's own model
|
| The token's tokenable is a `Customer`, never a `User`, and the `customer`
| middleware is what insists on that — a seller's token is a valid Sanctum
| token and would otherwise walk straight in.
|
| Every list and lookup is scoped by `ScopesToCustomer`. No endpoint takes a
| customer id from the caller.
|
*/

Route::prefix('customer')->name('api.customer.')->group(function () {

    /* -------------------------------------------------- browsing, signed out */

    // These work with or without a token. Where one is present the catalogue
    // resolves it through the `sanctum` guard by hand and stamps
    // `is_wishlisted`; where it is not, the field is simply absent.
    Route::get('home', [CustomerCatalogController::class, 'home'])->name('home');
    Route::get('categories', [CustomerCatalogController::class, 'categories'])->name('categories');
    Route::get('products', [CustomerCatalogController::class, 'products'])->name('products');
    Route::get('products/suggestions', [CustomerCatalogController::class, 'suggestions'])->name('products.suggestions');
    // Before `products/{product}`, or a product slugged "filters" would win.
    Route::get('products/filters', [CustomerCatalogController::class, 'filters'])->name('products.filters');
    Route::get('products/{product}', [CustomerCatalogController::class, 'product'])->name('products.show');
    Route::get('products/{product}/reviews', [ReviewController::class, 'index'])->name('products.reviews');
    Route::get('sellers/{vendor}', [CustomerCatalogController::class, 'seller'])->name('sellers.show');
    Route::get('reference', ReferenceController::class)->name('reference');

    /*
    | Words rather than shopping, and all of it open: a shopper who has been
    | signed out still has to be able to read the privacy policy and find a
    | phone number.
    */
    Route::get('app-config', [ContentController::class, 'appConfig'])->name('app-config');
    Route::get('support/config', [ContentController::class, 'supportConfig'])->name('support.config');
    Route::get('legal', [ContentController::class, 'legalIndex'])->name('legal.index');
    Route::get('legal/{page}', [ContentController::class, 'legal'])->name('legal.show');

    /*
    | The invoice, reached by signature rather than by token — so it can be
    | opened in a browser or handed to a download manager, neither of which
    | carries the app's bearer token. The signature is the authority; it is
    | minted only for the shopper whose order it is, and it expires.
    */
    Route::get('invoices/{order}', [InvoiceController::class, 'show'])
        ->middleware('signed')->whereNumber('order')->name('invoices.show');

    /*
    | Razorpay's own account of a payment. Public by necessity — a gateway
    | carries no token — and believed only because the body is signed. This is
    | also what saves an order when the app dies between paying and saying so.
    */
    Route::post('payments/webhook/razorpay', [PaymentController::class, 'razorpayWebhook'])
        ->name('payments.webhook.razorpay');

    /* ------------------------------------------------------------ getting in */

    Route::middleware('throttle:10,1')->group(function () {
        Route::post('auth/otp', [CustomerAuthController::class, 'requestCode'])->name('auth.otp');
        Route::post('auth/otp/verify', [CustomerAuthController::class, 'verifyCode'])->name('auth.otp.verify');
        Route::post('auth/register', [CustomerAuthController::class, 'register'])->name('auth.register');
        Route::post('auth/login', [CustomerAuthController::class, 'login'])->name('auth.login');
        Route::post('auth/forgot-password', [CustomerAuthController::class, 'forgotPassword'])->name('auth.forgot-password');
        Route::post('auth/reset-password', [CustomerAuthController::class, 'resetPassword'])->name('auth.reset-password');
    });

    /* ------------------------------------------------------------ signed in */

    Route::middleware(['auth:sanctum', 'customer'])->group(function () {

        Route::post('auth/refresh', [CustomerAuthController::class, 'refresh'])->name('auth.refresh');
        Route::post('auth/logout', [CustomerAuthController::class, 'logout'])->name('auth.logout');
        Route::post('auth/logout-all', [CustomerAuthController::class, 'logoutAll'])->name('auth.logout-all');
        Route::get('auth/devices', [CustomerAuthController::class, 'devices'])->name('auth.devices');
        Route::delete('auth/devices/{token}', [CustomerAuthController::class, 'revokeDevice'])->name('auth.devices.revoke');

        Route::get('me', [CustomerProfileController::class, 'show'])->name('me');
        Route::put('me', [CustomerProfileController::class, 'update'])->name('me.update');
        Route::put('me/password', [CustomerProfileController::class, 'updatePassword'])->name('me.password');
        // Before `me`, or the account screen's counts would be read as a
        // profile update.
        Route::get('me/summary', [CustomerProfileController::class, 'summary'])->name('me.summary');
        Route::delete('me', [CustomerProfileController::class, 'destroy'])->name('me.destroy');

        /*
        | The payments screen. `payment-methods` here is personal — the cards
        | and handles this shopper has saved — where `/reference` lists what
        | the marketplace accepts from anybody.
        */
        Route::get('payment-methods', [WalletController::class, 'index'])->name('payment-methods.index');
        Route::post('payment-methods', [WalletController::class, 'store'])->name('payment-methods.store');
        Route::delete('payment-methods/{method}', [WalletController::class, 'destroy'])
            ->whereNumber('method')->name('payment-methods.destroy');
        Route::patch('payment-methods/{method}/default', [WalletController::class, 'makeDefault'])
            ->whereNumber('method')->name('payment-methods.default');

        Route::get('wallet', [WalletController::class, 'wallet'])->name('wallet');

        Route::get('notification-preferences', [CustomerProfileController::class, 'notificationPreferences'])
            ->name('notification-preferences');
        Route::put('notification-preferences', [CustomerProfileController::class, 'updateNotificationPreferences'])
            ->name('notification-preferences.update');

        Route::get('addresses', [CustomerAddressController::class, 'index'])->name('addresses.index');
        Route::post('addresses', [CustomerAddressController::class, 'store'])->name('addresses.store');
        Route::put('addresses/{address}', [CustomerAddressController::class, 'update'])->name('addresses.update');
        Route::delete('addresses/{address}', [CustomerAddressController::class, 'destroy'])->name('addresses.destroy');
        Route::patch('addresses/{address}/default', [CustomerAddressController::class, 'setDefault'])->name('addresses.default');

        Route::get('cart', [CartController::class, 'show'])->name('cart');
        Route::post('cart/items', [CartController::class, 'addItem'])->name('cart.items.store');
        Route::patch('cart/items/{item}', [CartController::class, 'updateItem'])->name('cart.items.update');
        Route::delete('cart/items/{item}', [CartController::class, 'removeItem'])->name('cart.items.destroy');
        Route::post('cart/items/{item}/save-for-later', [CartController::class, 'saveForLater'])->name('cart.items.save');
        Route::post('cart/items/{item}/move-to-cart', [CartController::class, 'moveToCart'])->name('cart.items.move');
        Route::delete('cart', [CartController::class, 'clear'])->name('cart.clear');
        Route::get('cart/coupons', [CartController::class, 'coupons'])->name('cart.coupons');
        Route::post('cart/coupon', [CartController::class, 'applyCoupon'])->name('cart.coupon.apply');
        Route::delete('cart/coupon', [CartController::class, 'removeCoupon'])->name('cart.coupon.remove');

        Route::get('wishlist', [WishlistController::class, 'index'])->name('wishlist.index');
        Route::post('wishlist', [WishlistController::class, 'store'])->name('wishlist.store');
        Route::delete('wishlist/{product}', [WishlistController::class, 'destroy'])->name('wishlist.destroy');

        Route::get('checkout', [CheckoutController::class, 'options'])->name('checkout');
        Route::post('orders', [CheckoutController::class, 'store'])->name('orders.store');

        // Opening and settling a payment. The webhook above is the third path
        // and needs no token; these two are the app's own hands.
        Route::post('payments/create-intent', [PaymentController::class, 'createIntent'])->name('payments.intent');
        Route::post('payments/verify', [PaymentController::class, 'verify'])->name('payments.verify');

        Route::get('orders', [CustomerOrderController::class, 'index'])->name('orders.index');
        // Before {order}, or an order numbered "requests" would win the match.
        Route::get('requests', [RequestController::class, 'index'])->name('requests.index');
        Route::get('requests/{number}', [RequestController::class, 'show'])->name('requests.show');
        Route::post('requests/{number}/withdraw', [RequestController::class, 'withdraw'])->name('requests.withdraw');
        Route::get('orders/{order}', [CustomerOrderController::class, 'show'])->name('orders.show');
        Route::get('orders/{order}/track', [CustomerOrderController::class, 'track'])->name('orders.track');
        Route::get('orders/{order}/invoice', [InvoiceController::class, 'link'])->name('orders.invoice');
        Route::post('orders/{order}/reorder', [CustomerOrderController::class, 'reorder'])->name('orders.reorder');
        Route::post('orders/{order}/cancellations', [RequestController::class, 'storeCancellation'])->name('orders.cancellations');
        Route::post('orders/{order}/returns', [RequestController::class, 'storeRefund'])->name('orders.returns');

        Route::get('reviews', [ReviewController::class, 'mine'])->name('reviews.mine');
        Route::post('products/{product}/reviews', [ReviewController::class, 'store'])->name('reviews.store');
        Route::delete('reviews/{review}', [ReviewController::class, 'destroy'])->name('reviews.destroy');

        Route::get('notifications', [CustomerNotificationController::class, 'index'])->name('notifications.index');
        Route::get('notifications/unread-count', [CustomerNotificationController::class, 'unreadCount'])->name('notifications.unread');
        Route::post('notifications/read-all', [CustomerNotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::post('notifications/{notification}/read', [CustomerNotificationController::class, 'read'])
            ->whereUuid('notification')->name('notifications.read');
        Route::delete('notifications/{notification}', [CustomerNotificationController::class, 'destroy'])
            ->whereUuid('notification')->name('notifications.destroy');
        Route::post('push/device', [CustomerNotificationController::class, 'registerDevice'])->name('push.device.register');
        Route::delete('push/device', [CustomerNotificationController::class, 'forgetDevice'])->name('push.device.forget');
    });
});
