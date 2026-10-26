<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Parité de src/Controller/DashboardController.php :
 * - GET  /api/v1/dashboard      : profil client, abonnement actif sinon plus
 *   récent (W-9), factures, contrats, courriers, KYC (défaut
 *   ['statuts' => 'en_attente']), pastSubscriptions (L009) ;
 * - POST /api/v1/profil/update  : nom+email requis, e-mail unique hors
 *   client courant (409 'Cet e-mail est déjà utilisé'), htmlspecialchars.
 *
 * Tables (BE-SCHEMA) : clients, abonnements, factures, contrats, courriers, kyc.
 */
class DashboardController extends Controller
{
    /** GET /api/v1/dashboard */
    public function index(Request $request): JsonResponse
    {
        $clientId = $this->clientId($request);
        if ($clientId === null) {
            return response()->json(['error' => 'Connexion requise', 'login_required' => true], 401);
        }
        if ($this->isAdmin($request)) {
            return response()->json(['error' => 'Espace client réservé aux comptes clients', 'forbidden' => true], 403);
        }

        $client = DB::table('clients')->where('id', $clientId)
            ->first(['id', 'civilité', 'nom', 'entreprise', 'forme_juridique', 'email', 'telephone', 'adresse']);

        // W-9 : abonnement ACTIF le plus récent, sinon le plus récent
        $subscription = DB::table('abonnements')
            ->where('client_id', $clientId)->where('statut', 'actif')
            ->orderByDesc('id')->first()
            ?? DB::table('abonnements')
                ->where('client_id', $clientId)
                ->orderByDesc('id')->first();

        // L009 : anciens abonnements pour « Changer de Pack »
        $pastSubscriptions = DB::table('abonnements')
            ->where('client_id', $clientId)->where('statut', '!=', 'actif')
            ->orderByDesc('id')->get()->map(fn ($r) => (array) $r)->all();

        $factures = DB::table('factures')->where('client_id', $clientId)
            ->orderByDesc('id')->get()->map(fn ($r) => (array) $r)->all();

        $contrats = DB::table('contrats')->where('client_id', $clientId)
            ->orderByDesc('id')->get()->map(fn ($r) => (array) $r)->all();

        $courriers = DB::table('courriers')->where('client_id', $clientId)
            ->orderByDesc('id')->get()->map(fn ($r) => (array) $r)->all();

        $kyc = DB::table('kyc')->where('client_id', $clientId)
            ->orderByDesc('id')->first();

        return response()->json([
            'client' => $client,
            'subscription' => $subscription,
            'factures' => $factures,
            'contrats' => $contrats,
            'courriers' => $courriers,
            'kyc' => $kyc ?: ['statuts' => 'en_attente'],
            'pastSubscriptions' => $pastSubscriptions,
        ]);
    }

    /** POST /api/v1/profil/update */
    public function updateProfile(ProfileUpdateRequest $request): JsonResponse
    {
        $clientId = $this->clientId($request);
        if ($clientId === null) {
            return response()->json(['error' => 'Connexion requise', 'login_required' => true], 401);
        }

        try {
            $data = $request->validated();
            $email = trim($data['email']);

            if (DB::table('clients')->where('email', $email)->where('id', '!=', $clientId)->exists()) {
                return response()->json(['error' => 'Cet e-mail est déjà utilisé'], 409);
            }

            DB::table('clients')->where('id', $clientId)->update([
                'nom' => htmlspecialchars(trim($data['nom'])),
                'entreprise' => htmlspecialchars(trim($data['entreprise'] ?? '')),
                'email' => $email,
                'telephone' => htmlspecialchars(trim($data['telephone'] ?? '')),
                'adresse' => htmlspecialchars(trim($data['adresse'] ?? '')),
            ]);

            return response()->json(['message' => 'Profil mis à jour']);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Erreur interne'], 500);
        }
    }

    private function clientId(Request $request): ?int
    {
        $user = $request->user();
        if (! $user) {
            return null;
        }
        $client = DB::table('clients')->where('email', $user->email)->first();

        return $client ? (int) $client->id : null;
    }

    private function isAdmin(Request $request): bool
    {
        $user = $request->user();
        if (! $user) {
            return false;
        }
        $client = DB::table('clients')->where('email', $user->email)->first();

        return $client && ($client->role ?? 'client') === 'admin';
    }
}
