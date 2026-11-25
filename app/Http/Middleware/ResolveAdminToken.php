<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use App\Models\Admin;

/**
 * Résout le token Sanctum pour un administrateur avant que le guard
 * auth:sanctum (provider 'users') ne tente et échoue.
 *
 * Le token admin est créé sur App\Models\Admin (table admins), alors que
 * le guard sanctum est configuré avec le provider 'users' (modèle User).
 * Sans ce middleware, toutes les routes protégées par auth:sanctum
 * retournent 401 Unauthenticated pour un admin connecté.
 */
class ResolveAdminToken
{
    public function handle(Request $request, Closure $next)
    {
        // Si Sanctum a déjà résolu un utilisateur (cas client), on passe.
        if ($request->user('sanctum')) {
            return $next($request);
        }

        // Cas admin : résoudre manuellement le token via personal_access_tokens.
        $plainToken = $request->bearerToken();
        if ($plainToken) {
            $tokenRecord = PersonalAccessToken::findToken($plainToken);
            if ($tokenRecord && $tokenRecord->tokenable instanceof Admin) {
                $admin = $tokenRecord->tokenable;
                // Définir l'admin sur le guard sanctum pour les middlewares/contrôleurs suivants.
                app('auth')->guard('sanctum')->setUser($admin);
            }
        }

        return $next($request);
    }
}
