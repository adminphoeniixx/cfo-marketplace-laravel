<?php

namespace App\Http\Middleware;

use App\Support\Roles;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricted to the admin role only. Used for the role matrix, which decides
 * what everyone else can reach — granting it through the matrix itself would
 * let a manager quietly promote themselves.
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless(
            $user && $user->is_active && $user->role === Roles::ADMIN,
            403,
            'Only admins can manage roles.'
        );

        return $next($request);
    }
}
