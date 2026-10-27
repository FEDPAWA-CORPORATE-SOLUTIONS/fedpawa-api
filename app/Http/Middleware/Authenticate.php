<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;

/**
 * API stateless (tests-supervisors BUG-1) : aucune route « login »
 * n'existe côté API — le redirectTo() du framework lève
 * RouteNotFound → 500 + stack trace. En retournant null pour toute
 * requête api/*, Sanctum lève AuthenticationException, convertie en
 * 401 JSON par le handler (bootstrap/app.php).
 */
class Authenticate extends \Illuminate\Auth\Middleware\Authenticate
{
    protected function redirectTo(Request $request): ?string
    {
        if ($request->is('api/*')) {
            return null;
        }

        return parent::redirectTo($request);
    }
}
