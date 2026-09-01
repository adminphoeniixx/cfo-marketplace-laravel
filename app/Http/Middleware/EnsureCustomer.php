<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The shopper gate.
 *
 * `auth:sanctum` only proves the token is valid — it says nothing about which
 * model it belongs to, and a seller's token is just as valid. This is where
 * the customer API insists its caller is actually a customer, and an active
 * one: a blocked account keeps its token but stops being able to shop.
 */
class EnsureCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        // The sanctum guard, not the session one: this request arrived with a
        // token, and the token knows which model it belongs to.
        $customer = $request->user('sanctum');

        if (! $customer instanceof Customer) {
            abort(403, 'This endpoint is for customer accounts.');
        }

        if ($customer->status !== 'active') {
            abort(403, 'This account is not active.');
        }

        return $next($request);
    }
}
