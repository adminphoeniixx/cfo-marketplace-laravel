<?php

use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\AppContentController;
use App\Http\Controllers\Admin\AttributeController;
use App\Http\Controllers\Admin\CancellationController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CustomerAddressController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DeliveryPartnerController;
use App\Http\Controllers\Admin\BroadcastController;
use App\Http\Controllers\Admin\InvoiceController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\PaymentMethodController;
use App\Http\Controllers\Admin\PayoutController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\RefundController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SearchController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\ShippingController;
use App\Http\Controllers\Admin\TaxController;
use App\Http\Controllers\Admin\TeamController;
use App\Http\Controllers\Admin\TicketController;
use App\Http\Controllers\Admin\UploadController;
use App\Http\Controllers\Admin\VendorController;
use Illuminate\Support\Facades\Route;

// `deny.vendor` closes the whole panel to sellers, including the handful of
// routes below that carry no section gate — the dashboard and global search
// both read across the marketplace. Sellers get /seller instead.
Route::middleware(['auth', 'verified', 'deny.vendor'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('search', SearchController::class)->name('search');
    Route::post('uploads', [UploadController::class, 'store'])->name('uploads.store');

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |
    | Not gated by a section: these belong to the person, and every query is
    | scoped to the signed-in user's own pile.
    |--------------------------------------------------------------------------
    */
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::delete('notifications/read', [NotificationController::class, 'clearRead'])->name('notifications.clear-read');
    Route::post('notifications/push', [NotificationController::class, 'subscribe'])->name('notifications.push.subscribe');
    Route::delete('notifications/push', [NotificationController::class, 'unsubscribe'])->name('notifications.push.unsubscribe');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
    Route::delete('notifications/{notification}', [NotificationController::class, 'destroy'])->name('notifications.destroy');

    Route::middleware('role.can:analytics')->group(function () {
        Route::get('analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
        Route::get('analytics/export/{report}', [AnalyticsController::class, 'export'])->name('analytics.export');
    });

    /*
    |--------------------------------------------------------------------------
    | Catalog
    |--------------------------------------------------------------------------
    */
    Route::middleware('role.can:categories')
        ->resource('categories', CategoryController::class)->except('show');
    Route::middleware('role.can:attributes')
        ->resource('attributes', AttributeController::class)->except('show');

    Route::middleware('role.can:products')->group(function () {
        Route::post('products/bulk', [ProductController::class, 'bulk'])->name('products.bulk');
        Route::resource('products', ProductController::class);
    });

    /*
    |--------------------------------------------------------------------------
    | Customers
    |--------------------------------------------------------------------------
    */
    Route::middleware('role.can:customers')->group(function () {
        Route::patch('customers/{customer}/toggle-status', [CustomerController::class, 'toggleStatus'])
            ->name('customers.toggle-status');
        Route::resource('customers', CustomerController::class);
        Route::post('customers/{customer}/addresses', [CustomerAddressController::class, 'store'])
            ->name('customers.addresses.store');
        Route::put('customers/{customer}/addresses/{address}', [CustomerAddressController::class, 'update'])
            ->name('customers.addresses.update');
        Route::delete('customers/{customer}/addresses/{address}', [CustomerAddressController::class, 'destroy'])
            ->name('customers.addresses.destroy');
    });

    /*
    |--------------------------------------------------------------------------
    | Orders
    |--------------------------------------------------------------------------
    */
    Route::middleware('role.can:orders')->group(function () {
        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/create', [OrderController::class, 'create'])->name('orders.create');
        Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
        Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::put('orders/{order}', [OrderController::class, 'update'])->name('orders.update');
        Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.status');
        Route::patch('orders/{order}/payment', [OrderController::class, 'updatePayment'])->name('orders.payment');
        Route::post('orders/{order}/fulfill', [OrderController::class, 'fulfill'])->name('orders.fulfill');
        Route::post('orders/{order}/notes', [OrderController::class, 'addNote'])->name('orders.notes');
        // A redirect to the courier's own PDF — see the controller.
        Route::get('orders/{order}/label', [OrderController::class, 'label'])->name('orders.label');
        // A seller's own invoice for their part of the order. Support answers
        // for both sides of a marketplace, so it has to be able to open this.
        Route::get('orders/{order}/invoices/{vendor}', [InvoiceController::class, 'order'])
            ->whereNumber('vendor')->name('orders.invoice');
    });

    /*
    |--------------------------------------------------------------------------
    | Cancellations
    |--------------------------------------------------------------------------
    */
    Route::middleware('role.can:cancellations')->group(function () {
        Route::get('cancellations', [CancellationController::class, 'index'])->name('cancellations.index');
        Route::get('cancellations/create', [CancellationController::class, 'create'])->name('cancellations.create');
        Route::post('cancellations', [CancellationController::class, 'store'])->name('cancellations.store');
        Route::get('cancellations/{cancellation}', [CancellationController::class, 'show'])->name('cancellations.show');
        Route::patch('cancellations/{cancellation}/approve', [CancellationController::class, 'approve'])
            ->name('cancellations.approve');
        Route::patch('cancellations/{cancellation}/reject', [CancellationController::class, 'reject'])
            ->name('cancellations.reject');
    });

    /*
    |--------------------------------------------------------------------------
    | Refunds
    |--------------------------------------------------------------------------
    */
    Route::middleware('role.can:refunds')->group(function () {
        Route::get('refunds', [RefundController::class, 'index'])->name('refunds.index');
        Route::get('refunds/create', [RefundController::class, 'create'])->name('refunds.create');
        Route::post('refunds', [RefundController::class, 'store'])->name('refunds.store');
        Route::get('refunds/{refund}', [RefundController::class, 'show'])->name('refunds.show');
        Route::patch('refunds/{refund}/approve', [RefundController::class, 'approve'])->name('refunds.approve');
        Route::patch('refunds/{refund}/reject', [RefundController::class, 'reject'])->name('refunds.reject');
        Route::patch('refunds/{refund}/process', [RefundController::class, 'process'])->name('refunds.process');
    });

    /*
    |--------------------------------------------------------------------------
    | Marketplace
    |--------------------------------------------------------------------------
    */
    Route::middleware('role.can:vendors')->group(function () {
        Route::patch('vendors/{vendor}/status', [VendorController::class, 'updateStatus'])->name('vendors.status');
        Route::resource('vendors', VendorController::class);
    });

    Route::middleware('role.can:payouts')->group(function () {
        Route::get('payouts', [PayoutController::class, 'index'])->name('payouts.index');
        Route::post('payouts', [PayoutController::class, 'store'])->name('payouts.store');
        Route::patch('payouts/{payout}/status', [PayoutController::class, 'updateStatus'])->name('payouts.status');
        Route::get('payouts/{payout}/invoice', [InvoiceController::class, 'commission'])->name('payouts.invoice');
        Route::delete('payouts/{payout}', [PayoutController::class, 'destroy'])->name('payouts.destroy');
    });

    /*
    |--------------------------------------------------------------------------
    | Taxes
    |--------------------------------------------------------------------------
    */
    Route::middleware('role.can:taxes')->group(function () {
        Route::get('taxes', [TaxController::class, 'index'])->name('taxes.index');
        Route::post('taxes/classes', [TaxController::class, 'storeClass'])->name('taxes.classes.store');
        Route::put('taxes/classes/{taxClass}', [TaxController::class, 'updateClass'])->name('taxes.classes.update');
        Route::delete('taxes/classes/{taxClass}', [TaxController::class, 'destroyClass'])->name('taxes.classes.destroy');
        Route::post('taxes/rates', [TaxController::class, 'storeRate'])->name('taxes.rates.store');
        Route::put('taxes/rates/{taxRate}', [TaxController::class, 'updateRate'])->name('taxes.rates.update');
        Route::delete('taxes/rates/{taxRate}', [TaxController::class, 'destroyRate'])->name('taxes.rates.destroy');
    });

    /*
    |--------------------------------------------------------------------------
    | Payment methods
    |--------------------------------------------------------------------------
    */
    Route::middleware('role.can:payments')->group(function () {
        Route::get('payments', [PaymentMethodController::class, 'index'])->name('payments.index');
        Route::post('payments', [PaymentMethodController::class, 'store'])->name('payments.store');
        Route::put('payments/{payment}', [PaymentMethodController::class, 'update'])->name('payments.update');
        Route::patch('payments/{payment}/toggle', [PaymentMethodController::class, 'toggle'])->name('payments.toggle');
        Route::delete('payments/{payment}', [PaymentMethodController::class, 'destroy'])->name('payments.destroy');
    });

    /*
    |--------------------------------------------------------------------------
    | Support tickets
    |--------------------------------------------------------------------------
    */
    Route::middleware('role.can:tickets')->group(function () {
        Route::get('tickets', [TicketController::class, 'index'])->name('tickets.index');
        Route::get('tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
        Route::post('tickets/{ticket}/replies', [TicketController::class, 'reply'])->name('tickets.reply');
        Route::patch('tickets/{ticket}', [TicketController::class, 'update'])->name('tickets.update');
    });

    /*
    |--------------------------------------------------------------------------
    | Broadcasts
    |--------------------------------------------------------------------------
    |
    | The one screen that writes to shoppers unprompted. Gated on `customers`
    | rather than `settings`: it is a message to people, not a configuration.
    |
    */

    Route::middleware('role.can:customers')->group(function () {
        Route::get('broadcasts', [BroadcastController::class, 'index'])->name('broadcasts.index');
        Route::post('broadcasts', [BroadcastController::class, 'store'])->name('broadcasts.store');
    });

    /*
    |--------------------------------------------------------------------------
    | App content
    |--------------------------------------------------------------------------
    |
    | The home carousel, the help answers and the legal pages — everything the
    | shopper app used to carry in its own source. Gated on `settings` rather
    | than a section of its own: it is the same job as the store's own details,
    | and one more row in the role matrix for three small tables would be one
    | more thing to forget to grant.
    */
    Route::middleware('role.can:settings')->group(function () {
        Route::get('content', [AppContentController::class, 'index'])->name('content.index');

        Route::post('content/banners', [AppContentController::class, 'storeBanner'])->name('content.banners.store');
        Route::put('content/banners/{banner}', [AppContentController::class, 'updateBanner'])->name('content.banners.update');
        Route::patch('content/banners/{banner}/toggle', [AppContentController::class, 'toggleBanner'])->name('content.banners.toggle');
        Route::delete('content/banners/{banner}', [AppContentController::class, 'destroyBanner'])->name('content.banners.destroy');

        Route::post('content/faqs', [AppContentController::class, 'storeFaq'])->name('content.faqs.store');
        Route::put('content/faqs/{faq}', [AppContentController::class, 'updateFaq'])->name('content.faqs.update');
        Route::delete('content/faqs/{faq}', [AppContentController::class, 'destroyFaq'])->name('content.faqs.destroy');

        Route::put('content/pages/{page}', [AppContentController::class, 'updatePage'])->name('content.pages.update');
    });

    /*
    |--------------------------------------------------------------------------
    | Shipping
    |--------------------------------------------------------------------------
    */
    Route::middleware('role.can:shipping')->group(function () {
        Route::get('shipping', [ShippingController::class, 'index'])->name('shipping.index');
        Route::post('shipping/zones', [ShippingController::class, 'storeZone'])->name('shipping.zones.store');
        Route::put('shipping/zones/{shippingZone}', [ShippingController::class, 'updateZone'])->name('shipping.zones.update');
        Route::delete('shipping/zones/{shippingZone}', [ShippingController::class, 'destroyZone'])->name('shipping.zones.destroy');
        Route::post('shipping/rates', [ShippingController::class, 'storeRate'])->name('shipping.rates.store');
        Route::put('shipping/rates/{shippingRate}', [ShippingController::class, 'updateRate'])->name('shipping.rates.update');
        Route::delete('shipping/rates/{shippingRate}', [ShippingController::class, 'destroyRate'])->name('shipping.rates.destroy');
    });

    /*
    |--------------------------------------------------------------------------
    | Store settings
    |--------------------------------------------------------------------------
    */
    Route::middleware('role.can:settings')->group(function () {
        Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
        Route::put('settings', [SettingController::class, 'update'])->name('settings.update');

        // Delivery partners live on the store settings screen.
        Route::post('settings/delivery-partners', [DeliveryPartnerController::class, 'store'])
            ->name('delivery-partners.store');
        Route::put('settings/delivery-partners/{partner}', [DeliveryPartnerController::class, 'update'])
            ->name('delivery-partners.update');
        Route::patch('settings/delivery-partners/{partner}/toggle', [DeliveryPartnerController::class, 'toggle'])
            ->name('delivery-partners.toggle');
        // Ships nothing; only asks the courier whether the keys are real.
        Route::post('settings/delivery-partners/{partner}/test', [DeliveryPartnerController::class, 'test'])
            ->name('delivery-partners.test');
        // Asks for a van now, rather than waiting for the morning's run.
        Route::post('settings/delivery-partners/{partner}/pickup', [DeliveryPartnerController::class, 'pickup'])
            ->name('delivery-partners.pickup');
        Route::delete('settings/delivery-partners/{partner}', [DeliveryPartnerController::class, 'destroy'])
            ->name('delivery-partners.destroy');
    });

    /*
    |--------------------------------------------------------------------------
    | Team
    |--------------------------------------------------------------------------
    */
    Route::middleware('role.can:team')->group(function () {
        Route::get('team', [TeamController::class, 'index'])->name('team.index');
        Route::post('team', [TeamController::class, 'store'])->name('team.store');
        Route::put('team/{member}', [TeamController::class, 'update'])->name('team.update');
        Route::patch('team/{member}/toggle', [TeamController::class, 'toggle'])->name('team.toggle');
        Route::delete('team/{member}', [TeamController::class, 'destroy'])->name('team.destroy');
    });

    /*
    |--------------------------------------------------------------------------
    | Roles
    |
    | Admin-only on purpose: this matrix decides what every other role can
    | reach, so granting it through the matrix would be a way to self-promote.
    |--------------------------------------------------------------------------
    */
    Route::middleware('admin.only')->group(function () {
        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
        Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::post('roles/{role}/reset', [RoleController::class, 'reset'])->name('roles.reset');
    });
});
