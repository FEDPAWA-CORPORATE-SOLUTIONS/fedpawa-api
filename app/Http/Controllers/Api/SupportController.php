<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SupportStoreRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Parité de src/Controller/SupportController.php :
 * - GET  /api/v1/support/tickets : 50 derniers tickets du client connecté ;
 * - POST /api/v1/support         : création ticket (statut 'open') ;
 * - htmlspecialchars conservé (parité source) ;
 * - 401 non authentifié (auth:sanctum).
 *
 * Tables : tickets_support (client_id, sujet, message, statut, created_at,
 * reponse, reponse_at — colonnes réponse présentes côté admin BE-BUSINESS).
 */
class SupportController extends Controller
{
    /** GET /api/v1/support/tickets */
    public function myTickets(Request $request): JsonResponse
    {
        $clientId = $this->clientId($request);
        if ($clientId === null) {
            return response()->json(['error' => 'Non autorisé'], 401);
        }

        $tickets = DB::table('tickets_support')
            ->where('client_id', $clientId)
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'sujet', 'message', 'statut', 'created_at', 'reponse', 'reponse_at'])
            ->map(fn ($r) => (array) $r)
            ->all();

        return response()->json(['tickets' => $tickets]);
    }

    /** POST /api/v1/support */
    public function store(SupportStoreRequest $request): JsonResponse
    {
        $clientId = $this->clientId($request);
        if ($clientId === null) {
            return response()->json(['error' => 'Non autorisé'], 401);
        }

        try {
            DB::table('tickets_support')->insert([
                'client_id' => $clientId,
                'sujet' => htmlspecialchars(trim($request->validated('sujet'))),
                'message' => htmlspecialchars(trim($request->validated('message'))),
                'statut' => 'open',
                'created_at' => now()->format('Y-m-d H:i:s'),
            ]);

            return response()->json(['message' => 'Ticket de support envoyé']);
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
}
