<?php

namespace App\Http\Middleware;

use App\Support\Roles;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates an admin section against the signed-in user's role. Deactivated staff
 * are signed out rather than left staring at a 403 they cannot clear.
 */
class EnsureRoleCan
{
    public function handle(Request $request, Closure $next, string $section): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('error', 'Your account has been deactivated.');
        }

        // The panel's own view of the grant: a vendor's matrix row also governs
        // the seller app, which reaches further than the panel can scope.
        abort_unless(Roles::allowsInPanel($user, $section), 403, 'Your role does not have access to this section.');

        return $next($request);
    }
}
