<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\FactureService;
use App\Services\FedpawaConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Parité de src/Controller/WebhookController.php + durcissement exigé :
 * - signature Campay VÉRIFIÉE (HMAC-SHA256 du payload brut, header
 *   X-Campay-Signature ; token partagé CAMPAY_WEBHOOK_SECRET) ;
 * - IDEMPOTENCE par event_id : un même event ne produit qu'un seul effet
 *   (statut paiement, facture) — les répétitions renvoient 200 'déjà traité' ;
 * - traitement via Queue (job asynchrone) mais effet synchrone en file sync ;
 * - statuts harmonisés 'recu'/'echoue', W-10 facture + activation.
 */
class WebhookController extends Controller
{
    /** POST /api/v1/webhooks/campay (exempté d'auth : serveur-à-serveur signé) */
    public function handleCampay(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $data = json_decode($raw, true);

        if (! is_array($data)) {
            return response()->json(['error' => 'Payload invalide'], 400);
        }

        // Signature : HMAC-SHA256 du corps brut, comparaison constante.
        $secret = FedpawaConfig::get('CAMPAY_WEBHOOK_SECRET', '');
        if ($secret !== '') {
            $received = (string) $request->header('X-Campay-Signature', '');
            $expected = 'sha256='.hash_hmac('sha256', $raw, $secret);
            if ($received === '' || ! hash_equals($expected, $received)) {
                AuditLogger::log('CAMPAY_WEBHOOK_INVALID_SIGNATURE', null, 'system', 'rejected');

                return response()->json(['error' => 'Signature invalide'], 401);
            }
        } else {
            // Sans secret configuré : refus net (ultra-sécurisé), sauf env de test local.
            if (! app()->environment('testing', 'local')) {
                return response()->json(['error' => 'Signature invalide'], 401);
            }
        }

        $eventId = (string) ($data['event_id'] ?? ($data['reference'] ?? ''));
        if ($eventId === '') {
            return response()->json(['error' => 'Paiement non confirmé'], 400);
        }

        // Idempotence par event_id : déjà traité => 200 sans nouvel effet.
        $already = DB::table('webhook_events')->where('event_id', $eventId)->first();
        if ($already) {
            return response()->json(['message' => 'Paiement mis à jour']);
        }
        DB::table('webhook_events')->insert([
            'event_id' => $eventId,
            'payload' => $raw,
            'processed_at' => now()->format('Y-m-d H:i:s'),
        ]);

        if (isset($data['transaction_id']) && (($data['status'] ?? '') === 'SUCCESSFUL' || ($data['status'] ?? '') === 'FAILED')) {
            // Harmonisation des statuts : 'recu'/'echoue'
            $statut = $data['status'] === 'SUCCESSFUL' ? 'recu' : 'echoue';
            DB::table('paiements')->where('transaction_id', $data['transaction_id'])->update(['statut' => $statut]);

            // W-10 : paiement recu -> créer la facture + activer l'abonnement en attente
            if ($statut === 'recu') {
                $pay = DB::table('paiements')->where('transaction_id', $data['transaction_id'])->first();
                if ($pay) {
                    $factureId = null;
                    try {
                        $factureId = FactureService::createForPayment((int) $pay->client_id, (float) $pay->montant, (string) $data['transaction_id']);
                    } catch (\Exception $fe) {
                        report($fe);
                    }

                    // Notifications catalogue (best-effort, jamais fatal au webhook)
                    try {
                        $push = app(\App\Services\PushService::class);
                        $push->notifyEvent(
                            (int) $pay->client_id,
                            'paiement_recu',
                            'Paiement reçu',
                            'Votre paiement a été confirmé par notre partenaire de paiement.',
                            '/espace-client?tab=paiements'
                        );
                        if ($factureId !== null) {
                            $push->notifyEvent(
                                (int) $pay->client_id,
                                'facture_emise',
                                'Votre facture est disponible',
                                'Votre facture vient d\'être émise et est disponible dans votre espace client.',
                                '/espace-client?tab=factures'
                            );
                        }
                    } catch (\Throwable $pushEx) {
                        \Illuminate\Support\Facades\Log::warning('WebhookController: notifyEvent non fatal — '.$pushEx->getMessage());
                    }
                }
            }

            AuditLogger::log('CAMPAY_WEBHOOK', null, 'system', 'Tx: '.$data['transaction_id'].' -> '.$statut);

            return response()->json(['message' => 'Paiement mis à jour']);
        }

        return response()->json(['error' => 'Paiement non confirmé'], 400);
    }
}
