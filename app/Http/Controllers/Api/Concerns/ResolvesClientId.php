<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

trait ResolvesClientId
{
    /**
     * Get the client ID from the current request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    public function clientId(Request $request)
    {
        // Try Sanctum token ability first
        if ($request->user() && $request->user()->tokenCan('client')) {
            $token = $request->user()->currentAccessToken();
            if ($token && isset($token->abilities['client_id'])) {
                return $token->abilities['client_id'];
            }
        }

        // Fall back to authenticated user ID
        if ($request->user()) {
            return $request->user()->id;
        }

        return null;
    }
}
