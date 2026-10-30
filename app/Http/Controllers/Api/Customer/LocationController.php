<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Support\IndianStates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The address form's two pickers: state, then the cities in it.
 *
 * Open, because an address form is not the only thing that draws them — a
 * signed-out "deliver to" sheet does too.
 */
class LocationController extends Controller
{
    public function states(): JsonResponse
    {
        return response()->json(['data' => IndianStates::options()]);
    }

    public function cities(Request $request): JsonResponse
    {
        $request->validate(['state' => ['required', 'string', 'max:80']]);

        $state = IndianStates::canonical($request->string('state')->toString())
            ?? throw ValidationException::withMessages([
                'state' => 'Not an Indian state or union territory. Pick one from /states.',
            ]);

        return response()->json([
            // Echoed as the list spells it, so the app can store that.
            'state' => $state,
            'data' => IndianStates::cities($state),
        ]);
    }
}
