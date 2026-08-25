<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bounces a seller out of the marketplace panel, whatever they aimed at.
 *
 * `EnsureRoleCan` already refuses every *sectioned* admin route for a vendor,
 * but a handful are deliberately ungated because they belong to the person
 * rather than to a section — the dashboard, global search, the notification
 * centre. Two of those read straight across the marketplace: the dashboard
 * shows total sales and a vendor leaderboard, and search returns every store's
 * orders, products and customers.
 *
 * Rather than teach each of them who is asking, the whole panel is closed to
 * vendors here and they are sent to the one that is theirs.
 */
class DenyVendorAdminPanel
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isVendor()) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Sellers use the seller panel.'], 403)
                : redirect('/seller');
        }

        return $next($request);
    }
}
