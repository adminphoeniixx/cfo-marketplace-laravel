<?php

namespace App\Http\Middleware;

use App\Support\Roles;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The web twin of `EnsureSeller`: same three questions, answered with redirects
 * and 403 pages instead of JSON, because this guards Inertia screens.
 *
 * Unlike the admin panel's `EnsureRoleCan`, this asks `Roles::allows()` — the
 * role's real grant — rather than the narrowed `allowsInPanel()`. It can,
 * because every screen behind it scopes to the token holder's own store via
 * `ScopesToStore`. `VENDOR_PANEL_SECTIONS` exists to hold vendors out of the
 * *admin* screens, which do not scope that way; it has no say here.
 */
class EnsureSellerPanel
{
    public function handle(Request $request, Closure $next, ?string $section = null): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('error', 'Your account has been deactivated.');
        }

        // Staff who wander in are sent to the panel that is actually theirs,
        // rather than shown a 403 they can do nothing about.
        if (! $user?->isVendor()) {
            return redirect('/admin');
        }

        $vendor = $user->vendor;

        abort_unless($vendor !== null, 403, 'This seller account is not linked to a store.');

        if ($vendor->status !== 'approved') {
            abort(403, match ($vendor->status) {
                'pending' => 'Your store is still waiting to be approved.',
                'suspended' => 'Your store has been suspended. Contact support.',
                'rejected' => 'Your store application was not accepted.',
                default => 'Your store cannot trade at the moment.',
            });
        }

        abort_unless(
            $section === null || Roles::allows($user, $section),
            403,
            'The marketplace has turned this off for sellers.',
        );

        return $next($request);
    }
}
