<?php

namespace App\Http\Middleware;

use App\Support\Roles;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a seller endpoint against the same role matrix the admin panel uses.
 *
 * Without this the app is a way around the matrix: unchecking "Payouts" for the
 * vendor role hides the panel screen but leaves `/api/seller/payouts` serving.
 * One source of truth, two clients.
 *
 * Account-level endpoints — profile, store, notifications, devices — are
 * deliberately left ungated, exactly as the panel leaves its own notification
 * centre ungated: they belong to the person, not to a section.
 */
class EnsureSellerSection
{
    public function handle(Request $request, Closure $next, string $section): Response
    {
        // `seller` has already established there is an active vendor with an
        // approved store, so the only question left is the matrix.
        if (! Roles::allows($request->user(), $section)) {
            return response()->json([
                'message' => 'The marketplace has turned this off for sellers.',
                'section' => $section,
            ], 403);
        }

        return $next($request);
    }
}
