<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Converts the conventional `->with('success'|'error'|'warning'|'info', ...)`
 * redirect flashes into the toast payload the front-end already listens for.
 */
class ShareFlashToast
{
    /**
     * @var array<string, string>
     */
    protected const TYPES = [
        'success' => 'success',
        'error' => 'error',
        'warning' => 'warning',
        'info' => 'info',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        foreach (self::TYPES as $key => $type) {
            $message = $request->session()->get($key);

            if (is_string($message) && $message !== '') {
                Inertia::flash('toast', ['type' => $type, 'message' => $message]);

                break;
            }
        }

        return $next($request);
    }
}
