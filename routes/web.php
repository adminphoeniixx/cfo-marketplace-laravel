<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // Sellers get their own panel; everyone else is marketplace staff.
    Route::get('dashboard', fn () => redirect(
        request()->user()?->isVendor() ? '/seller' : '/admin'
    ))->name('dashboard');
});

require __DIR__.'/admin.php';
require __DIR__.'/seller.php';
require __DIR__.'/settings.php';
