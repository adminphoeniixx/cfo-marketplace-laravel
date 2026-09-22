<?php

use App\Http\Controllers\LegalPageController;
use App\Models\LegalPage;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// The footer links to whichever policies have actually been written, so a
// landing page never points at a 404.
Route::get('/', fn () => Inertia::render('Welcome', [
    'legal' => LegalPage::readable()->orderBy('title')->get(['slug', 'title']),
]))->name('home');

/*
| Terms, privacy and the rest, on a URL rather than behind a bearer token.
| Open on purpose: an app store reviewer, a shopper who has been signed out
| and a search engine all have to be able to read the privacy policy, and none
| of them is holding a token.
*/
Route::get('legal/{page}', [LegalPageController::class, 'show'])->name('legal.show');

// The address people type, and the one handed to the app stores.
Route::permanentRedirect('privacy', '/legal/privacy');

Route::middleware(['auth', 'verified'])->group(function () {
    // Sellers get their own panel; everyone else is marketplace staff.
    Route::get('dashboard', fn () => redirect(
        request()->user()?->isVendor() ? '/seller' : '/admin'
    ))->name('dashboard');
});

require __DIR__.'/admin.php';
require __DIR__.'/seller.php';
require __DIR__.'/settings.php';
