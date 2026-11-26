<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MailEnvoye;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * CONTRAT-MAIL.md §2 — webhook privé PrivateEmail : POST /api/v1/webhooks/privateemail.
 *
 * - AUCUNE auth de session ; signature HMAC vérifiée sur le corps brut
 *   (privateemail-signature / PRIVATEEMAIL_WEBHOOK_SECRET) ;
 *   signature invalide ou absente → 403, aucun enregistrement ;
 * - Traite les événements de statut des mails sortants :
 *   delivered, bounced, opened, clicked, complaint, unsubscribe ;
 * - Met à jour MailEnvoye.statut en conséquence (idempotent) ;
 * - Retourne 200 pour acquitter et éviter les retries.
 */
class PrivateEmailWebhookController extends Controller
{
    /** Événements PrivateEmail traités avec mise à jour de statut. */
    private const STATUT_PAR_EVENT = [
        'delivered'    => 'envoye',
        'bounced'      => 'echec',
        'rejected'     => 'echec',
        'failed'       => 'echec',
        'opened'       => 'envoye',
        'clicked'      => 'envoye',
        'complaint'    => 'echec',
        'unsubscribe'  => 'envoye',
    ];

    public function handle(Request $request): JsonResponse
    {
        $raw = $request->getContent();

        // Vérification de la signature HMAC PrivateEmail
        $signature = $request->header('privateemail-signature');
        $timestamp = $request->header('privateemail-timestamp');
        $secret = config('services.privateemail.webhook_secret', env('PRIVATEEMAIL_WEBHOOK_SECRET', ''));

        if (!$this->verifySignature($raw, $signature, $timestamp, $secret)) {
            AuditLogger::log('PRIVATEEMAIL_WEBHOOK_INVALID_SIGNATURE', null, 'system', 'rejected');
            Log::warning('PrivateEmailWebhookController: signature invalide — 403');

            return response()->json(['error' => 'Signature invalide'], 403);
        }

        $event = json_decode($raw, true);
        if (!is_array($event) || !isset($event['event'])) {
            return response()->json(['error' => 'Payload invalide'], 400);
        }

        $eventType = (string) $event['event'];
        $messageId = (string) ($event['message_id'] ?? $event['message-id'] ?? $event['id'] ?? '');
        $recipient = (string) ($event['recipient'] ?? '');
        $timestampEvent = $event['timestamp'] ?? null;

        if ($messageId === '') {
            return response()->json(['error' => 'message_id manquant'], 400);
        }

        // Chercher le mail par message_id ou resend_email_id (compatibilité)
        $mail = MailEnvoye::where('message_id', $messageId)
            ->orWhere('resend_email_id', $messageId)
            ->first();

        if (!$mail) {
            Log::warning("PrivateEmailWebhookController: mail introuvable pour message_id={$messageId}");

            return response()->json(['error' => 'Mail introuvable'], 404);
        }

        // Mise à jour du statut si l'événement est connu
        $nouveauStatut = self::STATUT_PAR_EVENT[$eventType] ?? null;
        if ($nouveauStatut !== null && $nouveauStatut !== $mail->statut) {
            $mail->statut = $nouveauStatut;
            if ($nouveauStatut === 'envoye') {
                $mail->envoye_le = now();
            }
            if ($nouveauStatut === 'echec' && isset($event['reason'])) {
                $mail->erreur = $event['reason'];
            }
            $mail->save();

            AuditLogger::log(
                'PRIVATEEMAIL_WEBHOOK_STATUS',
                $mail->id,
                'system',
                "Événement {$eventType} → statut {$nouveauStatut} pour mail #{$mail->id} ({$recipient})"
            );

            Log::info("PrivateEmailWebhook: mail #{$mail->id} statut mis à jour → {$nouveauStatut} (événement: {$eventType})");
        }

        return response()->json([
            'received' => true,
            'event' => $eventType,
            'message_id' => $messageId,
            'statut' => $mail->statut,
        ]);
    }

    /** Vérifie la signature HMAC SHA256 du webhook PrivateEmail. */
    private function verifySignature(string $raw, ?string $signature, ?string $timestamp, string $secret): bool
    {
        if ($signature === '' || $timestamp === '' || $secret === '') {
            return false;
        }

        // PrivateEmail signe : timestamp + '.' + corps, avec HMAC-SHA256(secret)
        $payload = $timestamp . '.' . $raw;
        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }
}
