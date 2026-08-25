<?php

use App\Http\Middleware\DenyVendorAdminPanel;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureRoleCan;
use App\Http\Middleware\EnsureSeller;
use App\Http\Middleware\EnsureSellerPanel;
use App\Http\Middleware\EnsureSellerSection;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ShareFlashToast;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            ShareFlashToast::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'role.can' => EnsureRoleCan::class,
            'admin.only' => EnsureAdmin::class,
            'deny.vendor' => DenyVendorAdminPanel::class,
            'seller' => EnsureSeller::class,
            'seller.panel' => EnsureSellerPanel::class,
            'seller.section' => EnsureSellerSection::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
