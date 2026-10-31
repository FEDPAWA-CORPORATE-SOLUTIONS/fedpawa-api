<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gating e-mail vérifié (CONTRAT-VERIF.md) : 403 {code:"email_non_verifie"}
 * sur les points d'entrée listés au contrat (création de souscription,
 * finalisation, vérification de paiement, push/subscribe).
 *
 * Les souscriptions DÉJÀ créées ne sont ni supprimées ni modifiées :
 * après vérification, le client les retrouve telles quelles.
 */
class EnsureEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request); // auth:sanctum gère le 401 avant nous
        }

        $client = DB::table('clients')->where('email', $user->email)->first();
        if ($client && $client->email_verified_at === null) {
            return response()->json([
                'code' => 'email_non_verifie',
                'message' => 'Vérifiez votre adresse e-mail pour continuer.',
            ], 403);
        }

        return $next($request);
    }
}
