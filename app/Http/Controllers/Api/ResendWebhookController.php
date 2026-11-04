<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\ResendInboundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * CONTRAT-MAIL.md §2 — webhook public Resend : POST /api/v1/webhooks/resend.
 *
 * - AUCUNE auth de session ; signature Svix vérifiée sur le corps brut
 *   (svix-id / svix-timestamp / svix-signature + RESEND_WEBHOOK_SECRET) ;
 *   signature invalide ou absente → 403, AUCUN enregistrement ;
 * - seul l'événement `email.received` est traité (autres types → 200 sans effet) ;
 * - le webhook ne porte que les métadonnées : le corps complet est récupéré
 *   via GET /emails/receiving/{email_id} puis enregistré (idempotent).
 */
class ResendWebhookController extends Controller
{
    public function handle(Request $request, ResendInboundService $inbound): JsonResponse
    {
        $raw = $request->getContent();

        $signatureOk = $inbound->verifySignature(
            $raw,
            $request->header('svix-id'),
            $request->header('svix-timestamp'),
            $request->header('svix-signature'),
        );
        if (! $signatureOk) {
            AuditLogger::log('RESEND_WEBHOOK_INVALID_SIGNATURE', null, 'system', 'rejected');
            Log::warning('ResendWebhookController: signature invalide — 403, aucun enregistrement');

            return response()->json(['error' => 'Signature invalide'], 403);
        }

        $event = json_decode($raw, true);
        if (! is_array($event) || ! isset($event['type'])) {
            return response()->json(['error' => 'Payload invalide'], 400);
        }

        $type = (string) $event['type'];
        if ($type !== 'email.received') {
            // Autres événements : acquittés sans effet (200 pour éviter les retries Resend).
            return response()->json(['received' => true, 'type' => $type, 'ignored' => true]);
        }

        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        $emailId = (string) ($data['email_id'] ?? '');
        if ($emailId === '') {
            return response()->json(['error' => 'email_id manquant'], 400);
        }

        // Idempotence : déjà connu → 200 sans nouvel effet, sans re-fetch.
        $existing = \App\Models\MailRecu::where('resend_email_id', $emailId)->first();
        if ($existing) {
            return response()->json(['received' => true, 'id' => $existing->id, 'duplicate' => true]);
        }

        // Corps complet via l'API Resend (le webhook ne contient que les métadonnées).
        $full = $inbound->fetchReceived($emailId);
        if ($full === null) {
            // 502 → Resend retente le webhook (transitoire) ; rien n'est enregistré.
            Log::warning('ResendWebhookController: corps introuvable côté Resend pour cet email_id');

            return response()->json(['error' => 'Corps du message introuvable'], 502);
        }

        [$mail, $duplicate] = $inbound->storeReceived($data, $full);
        if (! $mail) {
            return response()->json(['error' => 'Enregistrement impossible'], 500);
        }

        return response()->json(['received' => true, 'id' => $mail->id, 'duplicate' => $duplicate]);
    }
}
