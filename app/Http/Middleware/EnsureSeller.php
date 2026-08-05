<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every seller endpoint runs through here. It answers one question — is this
 * token allowed to act as a store right now — so no controller has to.
 *
 * `approved` mode additionally requires the store to have been let in. A
 * pending seller can still sign in and read their own profile, which is what
 * makes the "waiting for approval" screen possible.
 */
class EnsureSeller
{
    public function handle(Request $request, Closure $next, string $mode = 'approved'): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            return response()->json([
                'message' => 'This account is no longer active.',
            ], 403);
        }

        if (! $user->isVendor()) {
            return response()->json([
                'message' => 'This token does not belong to a seller account.',
            ], 403);
        }

        $vendor = $user->vendor;

        if (! $vendor) {
            return response()->json([
                'message' => 'This seller account is not linked to a store.',
            ], 403);
        }

        if ($mode === 'approved' && $vendor->status !== 'approved') {
            return response()->json([
                'message' => match ($vendor->status) {
                    'pending' => 'Your store is still waiting to be approved.',
                    'suspended' => 'Your store has been suspended. Contact support.',
                    'rejected' => 'Your store application was not accepted.',
                    default => 'Your store cannot trade at the moment.',
                },
                'store_status' => $vendor->status,
            ], 403);
        }

        return $next($request);
    }
}
