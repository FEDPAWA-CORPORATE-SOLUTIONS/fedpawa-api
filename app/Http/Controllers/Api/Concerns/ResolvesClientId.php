<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

trait ResolvesClientId
{
    /**
     * Get the client ID from the current request.
     *
     * Tries multiple sources in order:
     * 1. Sanctum token ability (client_id stored in token)
     * 2. Authenticated user's ID
     * 3. None (returns null)
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    public function clientId(Request $request)
    {
        // Try Sanctum token ability first
        if ($request->user() && $request->user()->hasAbility('client')) {
            $ability = $request->user()->ability('client');
            if ($ability && isset($ability['client_id'])) {
                return $ability['client_id'];
            }
        }

        // Fall back to authenticated user ID
        if ($request->user()) {
            return $request->user()->id;
        }

        // Try extracting from token directly (Sanctum token plain)
        $token = $request->bearerToken();
        if ($token) {
            // Attempt to parse a known token structure if needed
            // For now, return null — the caller will handle 404
        }

        return null;
    }
}