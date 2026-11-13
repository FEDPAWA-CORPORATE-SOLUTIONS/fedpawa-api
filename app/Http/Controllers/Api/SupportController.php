<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SupportStoreRequest;
use App\Models\Client;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
    public function __construct(private NotificationService $notifier) {}

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
            $ticketId = DB::table('tickets_support')->insertGetId([
                'client_id' => $clientId,
                'sujet' => htmlspecialchars(trim($request->validated('sujet'))),
                'message' => htmlspecialchars(trim($request->validated('message'))),
                'statut' => 'open',
                'created_at' => now()->format('Y-m-d H:i:s'),
            ]);

            // E-mail de confirmation au client
            $client = Client::find($clientId);
            if ($client) {
                $this->notifier->sendEventEmail(
                    $client,
                    'ticket_reponse',
                    'Votre ticket de support a été reçu — FEDPAWA',
                    'emails.09-ticket-reponse',
                    [
                        'client_nom' => $client->nom,
                        'ticket_id' => $ticketId,
                        'ticket_sujet' => htmlspecialchars(trim($request->validated('sujet'))),
                        'agent_nom' => 'Équipe FEDPAWA',
                        'date_reponse' => now()->format('d/m/Y H:i'),
                        'reponse_extrait' => 'Votre demande a bien été enregistrée. Un conseiller vous répondra sous les plus brefs délais.',
                        'lien_action' => config('app.url', 'https://app.fedpawa.com').'/espace-client?tab=support',
                    ]
                );
            }

            // E-mail d'alerte à l'administration
            try {
                \Illuminate\Support\Facades\Mail::raw(
                    'Nouveau ticket de support #'.$ticketId.' de '.htmlspecialchars($client?->nom ?? 'Inconnu').' ('.htmlspecialchars($client?->email ?? '').'). Sujet : '.htmlspecialchars(trim($request->validated('sujet'))).'.',
                    function ($message) use ($ticketId) {
                        $message->to(config('mail.from.address', 'contact@fedpawa.com'))
                            ->subject('Nouveau ticket de support #'.$ticketId.' — FEDPAWA');
                    }
                );
            } catch (\Throwable $mailEx) {
                Log::warning('Alerte admin nouveau ticket échouée : '.$mailEx->getMessage());
            }

            return response()->json(['message' => 'Ticket de support envoyé', 'ticket_id' => $ticketId]);
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
