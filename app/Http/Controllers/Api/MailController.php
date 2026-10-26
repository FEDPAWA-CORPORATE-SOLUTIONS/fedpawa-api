<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MailReplyRequest;
use App\Http\Requests\MailSendRequest;
use App\Services\AuditLogger;
use App\Services\InternalMailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Parité de src/Controller/InternalMailController.php :
 * - POST /api/v1/mail/send   : client -> admin ('Message envoyé') ;
 * - POST /api/v1/mail/reply  : admin -> client, 404 si client introuvable ;
 * - GET  /api/v1/mail/thread : fil du client, is_read passé à 1 (L020).
 * Codes legacy : 403 non autorisé (ici auth:sanctum + garde interne),
 * 400 champs manquants (FormRequest), 500 erreur interne.
 */
class MailController extends Controller
{
    public function __construct(private InternalMailService $mail) {}

    /** POST /api/v1/mail/send */
    public function send(MailSendRequest $request): JsonResponse
    {
        $clientId = $this->clientId($request);
        if ($clientId === null) {
            return response()->json(['error' => 'Non autorisé'], 403);
        }

        try {
            $this->mail->send($clientId, 'client', null, 'admin', trim($request->validated('content')));
            AuditLogger::log('MAIL_SENT', $clientId, 'client', 'To admin');

            return response()->json(['message' => 'Message envoyé']);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Erreur interne'], 500);
        }
    }

    /** POST /api/v1/mail/reply (admin) */
    public function reply(MailReplyRequest $request): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['error' => 'Non autorisé'], 403);
        }

        try {
            $clientId = (int) $request->validated('client_id');
            if (! DB::table('clients')->where('id', $clientId)->exists()) {
                return response()->json(['error' => 'Client introuvable'], 404);
            }

            $adminId = $this->adminId($request);
            $this->mail->send($adminId, 'admin', $clientId, 'client', trim($request->validated('content')));
            AuditLogger::log('MAIL_REPLIED', $clientId, 'admin', 'Reply sent');

            return response()->json(['message' => 'Réponse envoyée']);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Erreur interne'], 500);
        }
    }

    /** GET /api/v1/mail/thread */
    public function thread(Request $request): JsonResponse
    {
        $clientId = $this->clientId($request);
        if ($clientId === null) {
            return response()->json(['error' => 'Non autorisé'], 403);
        }

        try {
            return response()->json(['data' => $this->mail->getThread($clientId)]);
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

    /** Parité garde admin legacy : session admin OU client role='admin'. */
    private function isAdmin(Request $request): bool
    {
        $user = $request->user();
        if (! $user) {
            return false;
        }
        $client = DB::table('clients')->where('email', $user->email)->first();

        return $client && ($client->role ?? 'client') === 'admin';
    }

    private function adminId(Request $request): ?int
    {
        $client = DB::table('clients')->where('email', $request->user()->email)->first();

        return $client ? (int) $client->id : null;
    }
}
