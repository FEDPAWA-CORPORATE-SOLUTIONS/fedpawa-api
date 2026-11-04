<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PaiementVerifierRequest;
use App\Http\Requests\SignatureFinalizeRequest;
use App\Http\Requests\SouscriptionStoreRequest;
use App\Services\AuditLogger;
use App\Services\CampayService;
use App\Services\FactureService;
use App\Services\FedpawaConfig;
use App\Services\NotificationService;
use App\Services\PushService;
use App\Services\OtpService;
use App\Services\PdfService;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Parité de src/Controller/SubscriptionController.php :
 * process() / finalize() / verifyPayment() + L018 preview + L022 download.
 * Garde : client connecté requis (requireClient source) → sanctum + policy
 * de rôle (le client connecté prime : email/nom de session).
 */
class SouscriptionController extends Controller
{
    public function __construct(
        private SubscriptionService $subService,
        private PdfService $pdfService,
        private CampayService $campay,
        private NotificationService $notifier,
        private PushService $push,
    ) {}

    /**
     * Mode démo explicite : l'OTP n'est exposé dans la réponse JSON QUE si
     * APP_DEBUG est actif ET FEDPAWA_DEMO_MODE=true (tests e2e). Le mode
     * reste opt-in via la config : config('fedpawa.FEDPAWA_DEMO_MODE') ou
     * env FEDPAWA_DEMO_MODE prévalent sur l'env brut.
     */
    private static function demoMode(): bool
    {
        return (bool) config('app.debug')
            && FedpawaConfig::bool('FEDPAWA_DEMO_MODE', false);
    }

    /** POST /api/v1/souscription — initialise souscription + paiement Campay. */
    public function process(SouscriptionStoreRequest $request): JsonResponse
    {
        $user = $request->user();
        $input = $request->all();

        try {
            // Lane #4 : le client connecté prime — on rattache la souscription à son compte
            $sessionClient = DB::table('clients')->where('email', $user->email)->first();
            if ($sessionClient) {
                $input['email'] = $sessionClient->email;
                $input['nom'] = $input['nom'] ?? $sessionClient->nom;
            } else {
                // Premier achat : le compte API est le client (parité session client_id)
                $input['email'] = $user->email;
                $input['nom'] = $input['nom'] ?? $user->name;
            }

            // 1. Initialiser la souscription
            $sub = $this->subService->store($input);

            // 2. Initier le paiement Campay (ou mode démo si CAMPAY_ENABLED=0)
            $campayEnabled = FedpawaConfig::bool('CAMPAY_ENABLED', true);
            // Devis (service Recouvrement, prix=0) : aucune initiation Campay —
            // la souscription est enregistrée et finalisée par signature OTP,
            // le montant réel sera facturé plus tard (facture émise par l'admin).
            $devis = ((float) ($input['prix'] ?? 0)) <= 0.0;
            $transactionId = null;
            if ($campayEnabled && ! $this->campay->isConfigured()) {
                // Audit : CAMPAY_ENABLED actif mais aucun credential Campay
                // => dégradation explicite, pas un 500 avec trace.
                return response()->json([
                    'status' => 'payment_unavailable',
                    'error' => "Le service de paiement Campay n'est pas configuré. Contactez le support (CAMPAY_APP_USERNAME/CAMPAY_APP_SECRET (ou CAMPAY_APP_ID/CAMPAY_APP_SECRET) ou CAMPAY_TOKEN manquants).",
                ], 503);
            }
            // Règle temporaire de test Campay LIVE : numéro 672039863 => 500 FCFA forcé
            if ((string) ($input['telephone'] ?? '') === '672039863') {
                $input['prix'] = 500.0;
            }
            if ($campayEnabled && ! $devis) {
                try {
                    $payment = $this->campay->collect((float) $input['prix'], (string) ($input['telephone'] ?? ''), 'SUB_'.$sub['subscription_id']);
                    $transactionId = $payment['reference'] ?? $payment['transaction_id'] ?? null;
                } catch (\Exception $e) {
                    report($e);

                    return response()->json([
                        'status' => 'payment_init_failed',
                        'error' => "Impossible d'initier le paiement. Réessayez.",
                    ], 502);
                }
            } elseif (! $campayEnabled) {
                // Mode démo : référence simulée, le « Vérifier Mon Paiement » la confirmera
                $transactionId = 'DEMO-SUB-'.$sub['subscription_id'];
            }
            // (devis : ni Campay ni démo — transactionId reste null, signature OTP seule)

            // 3. Enregistrer le paiement en attente (rapprochement webhook ou vérification)
            DB::table('paiements')->insert([
                'client_id' => $sub['client_id'],
                'montant' => (float) $input['prix'],
                'mode_paiement' => 'campay',
                'transaction_id' => (string) ($transactionId ?? ''),
                'date' => now()->format('Y-m-d H:i:s'),
                'statut' => 'en_attente',
            ]);

            // 4. Générer l'OTP pour la signature.
            // Sécurité (audit) : l'OTP ne part QUE par e-mail et n'est JAMAIS
            // écrit en clair dans les logs. Il n'apparaît dans la réponse JSON
            // QUE si le mode démo est explicitement activé (APP_DEBUG +
            // FEDPAWA_DEMO_MODE=true, tests e2e uniquement).
            $otp = OtpService::generate($sub['client_id']);
            $clientEmail = (string) ($input['email'] ?? $user->email);
            $otpDelivered = false;
            try {
                $otpDelivered = $this->notifier->sendSignatureOtp($clientEmail, $otp);
            } catch (\Throwable $mailEx) {
                report($mailEx);
            }
            if (! $otpDelivered) {
                // Le code n'est JAMAIS écrit en clair dans les logs (interdit).
                Log::info("[OTP-SIGN] transport e-mail non configuré — aucun code envoyé ni journalisé pour {$clientEmail}");
            }

            AuditLogger::log('SUBSCRIPTION_INIT', $sub['client_id'], 'client', 'Sub ID: '.$sub['subscription_id']);

            $payload = [
                'status' => 'payment_pending',
                'transaction_id' => $transactionId,
                'subscription_id' => $sub['subscription_id'],
                'client_id' => $sub['client_id'],
            ];
            if (self::demoMode()) {
                $payload['signature_otp'] = $otp;
            }

            return response()->json($payload);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /** POST /api/v1/signature — finalise la souscription (OTP + contrat domiciliation). */
    public function finalize(SignatureFinalizeRequest $request): JsonResponse
    {
        $user = $request->user();
        try {
            $input = $request->all();
            $clientId = (int) $input['client_id'];

            // Vérifier que l'abonnement appartient bien à ce client
            $sub = DB::table('abonnements')
                ->join('clients', 'clients.id', '=', 'abonnements.client_id')
                ->where('abonnements.id', (int) $input['sub_id'])
                ->where('abonnements.client_id', $clientId)
                ->select('abonnements.*', 'clients.nom', 'clients.email', 'clients.entreprise')
                ->first();
            if (! $sub) {
                return response()->json(['error' => 'Souscription introuvable'], 404);
            }
            // Parité espace client : le token API doit correspondre au client facturé
            if ($user->email !== $sub->email) {
                return response()->json(['error' => 'Souscription introuvable'], 404);
            }

            // Lane contrat : signature possible uniquement si le paiement est reçu
            $pay = DB::table('paiements')->where('client_id', $clientId)->orderByDesc('id')->first();
            $campayEnabled = FedpawaConfig::bool('CAMPAY_ENABLED', true);
            if ($campayEnabled && (! $pay || $pay->statut !== 'recu')) {
                return response()->json([
                    'error' => 'Paiement non confirmé. Utilisez « Vérifier Mon Paiement » avant de signer.',
                ], 402);
            }
            // L'OTP est le facteur d'authentification de la signature
            if (! OtpService::verify($clientId, (string) $input['otp'])) {
                return response()->json(['error' => 'Signature invalide'], 400);
            }

            // Lane#1 : le contrat PDF + signature ne concernent QUE la domiciliation.
            $isDomiciliation = str_starts_with((string) $sub->pack, 'national-')
                || str_starts_with((string) $sub->pack, 'international-');

            if ($isDomiciliation) {
                // Finaliser : générer le PDF, marquer actif, notifier
                $pdf = $this->pdfService->generateContract([
                    'client_name' => $sub->nom,
                    'company_name' => $sub->entreprise ?? ($sub->denomination ?? 'Entreprise'),
                    'price' => $sub->prix,
                    'details' => $sub->details ?? null,
                ]);
                Storage::disk('local')->put('documents/contrat_'.$sub->id.'.pdf', $pdf);

                DB::table('abonnements')->where('id', $sub->id)->update(['statut' => 'actif']);
                $contratId = (int) DB::table('contrats')->insertGetId([
                    'client_id' => $clientId,
                    'type' => 'domiciliation',
                    'fichier' => 'documents/contrat_'.$sub->id.'.pdf',
                    'date_signature' => now()->format('Y-m-d H:i:s'),
                ]);

                $this->notifier->sendWelcomeEmail($sub->email, $sub->nom);

                // Notification catalogue : contrat_signe (best-effort, jamais fatal)
                $this->push->notifyEvent(
                    $clientId,
                    'contrat_signe',
                    'Votre contrat est prêt',
                    'Votre contrat de domiciliation a été signé et est disponible dans votre espace client.',
                    '/espace-client?tab=documents'
                );

                OtpService::clear($clientId);
                AuditLogger::log('SUBSCRIPTION_FINALIZED', $clientId, 'client', 'Sub ID: '.$sub->id.' (contrat domiciliation)');

                return response()->json([
                    'message' => 'Souscription finalisée',
                    'contrat_url' => '/api/v1/document?type=contrat&id='.$contratId,
                ]);
            }

            // Autres services : finalisation SANS contrat (dossier en traitement)
            DB::table('abonnements')->where('id', $sub->id)->update(['statut' => 'actif']);
            $this->notifier->sendWelcomeEmail($sub->email, $sub->nom);
            OtpService::clear($clientId);
            AuditLogger::log('SUBSCRIPTION_FINALIZED', $clientId, 'client', 'Sub ID: '.$sub->id.' (sans contrat — service non domiciliation)');

            return response()->json([
                'message' => 'Paiement confirmé — votre dossier est en traitement. Un juriste FEDPAWA vous recontacte sous 24 heures ouvrées.',
            ]);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['error' => 'Erreur interne lors de la finalisation'], 500);
        }
    }

    /** POST /api/v1/paiement/verifier — statut Campay, W-10 facture + activation. */
    public function verifyPayment(PaiementVerifierRequest $request): JsonResponse
    {
        $user = $request->user();
        try {
            $subId = (int) $request->input('sub_id');
            // clientId = clients row du user API (parité $_SESSION['client_id'])
            $client = DB::table('clients')->where('email', $user->email)->first();
            if (! $client) {
                return response()->json(['error' => 'Souscription introuvable'], 404);
            }
            $clientId = (int) $client->id;

            $sub = DB::table('abonnements')->where('id', $subId)->where('client_id', $clientId)->first();
            if (! $sub) {
                return response()->json(['error' => 'Souscription introuvable'], 404);
            }

            $pay = DB::table('paiements')->where('client_id', $clientId)->orderByDesc('id')->first();

            $campayEnabled = FedpawaConfig::bool('CAMPAY_ENABLED', true);
            $statut = 'en_attente';
            $transactionId = (string) ($pay->transaction_id ?? '');

            if ($campayEnabled && ! $this->campay->isConfigured()) {
                // Audit : CAMPAY_ENABLED actif mais aucun credential Campay
                // (token ni user/password) => dégradation explicite, pas un 500.
                return response()->json([
                    'error' => "Le service de paiement Campay n'est pas configuré. Contactez le support (CAMPAY_TOKEN ou CAMPAY_USER/CAMPAY_PASSWORD manquants).",
                ], 503);
            }

            if ($campayEnabled && $transactionId !== '') {
                // Production : statut réel côté Campay
                $status = $this->campay->checkStatus($transactionId);
                $statut = (($status['status'] ?? '') === 'SUCCESSFUL') ? 'recu' : 'en_attente';
                if ($pay) {
                    DB::table('paiements')->where('id', $pay->id)->update(['statut' => $statut]);
                }
            } elseif (! $campayEnabled) {
                // Mode demo : paiement considéré reçu dès la vérification (parité)
                if ($pay) {
                    $statut = 'recu';
                    DB::table('paiements')->where('id', $pay->id)->update(['statut' => $statut]);
                }
            }

            // W-10 : paiement recu -> facture + activation de l'abonnement en attente
            if ($statut === 'recu') {
                try {
                    FactureService::createForPayment($clientId, (float) $sub->prix, $transactionId);
                } catch (\Exception $fe) {
                    report($fe);
                }

                // Notifications catalogue : paiement_recu + facture_emise + abonnement
                // (une seule fois par transition en_attente -> recu)
                if (! $pay || $pay->statut !== 'recu') {
                    $this->push->notifyEvent(
                        $clientId,
                        'paiement_recu',
                        'Paiement reçu',
                        'Votre paiement a été confirmé. Merci pour votre confiance.',
                        '/espace-client?tab=paiements'
                    );
                    $this->push->notifyEvent(
                        $clientId,
                        'facture_emise',
                        'Votre facture est disponible',
                        'Votre paiement a été confirmé. La facture est disponible dans votre espace client.',
                        '/espace-client?tab=factures'
                    );
                    $this->push->sendToClient(
                        $clientId,
                        'abonnement',
                        'Abonnement activé',
                        "Votre abonnement « {$sub->pack} » est actif.",
                        '/espace-client?tab=abonnements'
                    );
                }
            }

            return response()->json([
                'status' => ($statut === 'recu') ? 'paid' : 'pending',
                'sub_id' => $subId,
                'client_id' => $clientId,
                'prix' => (float) $sub->prix,
                'transaction_id' => $transactionId,
            ]);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['error' => 'Erreur lors de la vérification du paiement'], 500);
        }
    }
}
