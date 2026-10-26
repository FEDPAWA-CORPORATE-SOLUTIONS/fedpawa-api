<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Garde admin pour les routes métier BE-BUSINESS (kyc approve/reject).
 * Parité requireAdmin() source : 401 si non-connecté, 403 si client.
 * Le rôle vit dans la table clients (role='admin'), parité $_SESSION['role'].
 */
class CheckAdminRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Connexion requise', 'login_required' => true], 401);
        }
        $isAdmin = DB::table('clients')->where('email', $user->email)->where('role', 'admin')->exists();
        if (! $isAdmin) {
            return response()->json(['error' => 'Accès administrateur requis', 'forbidden' => true], 403);
        }

        return $next($request);
    }
}
