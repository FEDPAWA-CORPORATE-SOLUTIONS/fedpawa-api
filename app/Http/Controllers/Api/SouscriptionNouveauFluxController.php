<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ContractDraft;
use App\Services\AuditLogger;
use App\Services\CampayService;
use App\Services\FedpawaConfig;
use App\Services\NotificationService;
use App\Services\OpenSignService;
use App\Services\OtpService;
use App\Services\PdfService;
use App\Services\PushService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class SouscriptionNouveauFluxController extends Controller
{
    /** Catalogue de packs côté serveur (prix mensuel en FCFA — source de vérité). */
    private const PACKS_SERVER = [
        'national-essential' => 25000,
        'national-business' => 45000,
        'national-premium' => 70000,
        'international-essential' => 50000,
        'international-business' => 110000,
        'international-premium' => 175000,
    ];

    /** TTL OTP signature : 24 heures (86400 secondes). */
    private const OTP_SIGNATURE_TTL = 86400;

    public function __construct(
        private PdfService $pdfService,
        private CampayService $campay,
        private OpenSignService $opensign,
        private NotificationService $notifier,
        private PushService $push,
    ) {}

    /** POST /api/v1/souscription-nouveau-flux/draft — Étape 1 : création du dossier DRAFT */
    public function createDraft(Request $request): JsonResponse
    {
        $input = $request->validate([
            'email' => 'required|email',
            'pack' => 'required|string|max:255',
            'nom' => 'required|string|max:255',
            'entreprise' => 'nullable|string|max:255',
            'telephone' => 'nullable|string|max:32',
            'adresse' => 'nullable|string|max:500',
            'rccm' => 'nullable|string|max:255',
            'niu' => 'nullable|string|max:255',
            'representant' => 'nullable|string|max:255',
            'qualite' => 'nullable|string|max:255',
        ]);

        $client = DB::table('clients')->where('email', $input['email'])->first();
        if (!$client) {
            return response()->json(['error' => 'Client introuvable pour cet e-mail.'], 404);
        }

        $prix = $this->prixServeur($input['pack'], 'mensuel');
        $flowToken = bin2hex(random_bytes(16));

        $draft = ContractDraft::create([
            'client_id' => $client->id,
            'signer_email' => $input['email'],
            'payer_email' => $input['email'],
            'flow_token' => $flowToken,
            'statut' => 'draft',
            'pack' => $input['pack'],
            'prix' => $prix,
            'contrat_data' => [
                'nom' => $input['nom'],
                'entreprise' => $input['entreprise'] ?? '',
                'telephone' => $input['telephone'] ?? '',
                'adresse' => $input['adresse'] ?? '',
                'rccm' => $input['rccm'] ?? '',
                'niu' => $input['niu'] ?? '',
                'representant' => $input['representant'] ?? $input['nom'],
                'qualite' => $input['qualite'] ?? 'Représentant légal',
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'session_id' => 'api',
        ]);

        AuditLogger::log(
            'CONTRACT_DRAFT_CREATED',
            (int) $client->id,
            'client',
            json_encode([
                'flow_token' => $flowToken,
                'pack' => $input['pack'],
                'prix' => $prix,
                'ip' => $request->ip(),
                'timestamp_utc' => now()->toIso8601String(),
            ])
        );

        return response()->json([
            'status' => 'draft_created',
            'flow_token' => $flowToken,
            'draft_id' => $draft->id,
            'statut' => 'draft',
            'pack' => $input['pack'],
            'prix' => $prix,
            'message' => 'Dossier de transaction créé. Étape suivante : lecture et approbation du contrat.',
        ]);
    }

    /** GET /api/v1/souscription-nouveau-flux/{draftId} — Récupération des données du draft */
    public function getDraft(int $draftId): JsonResponse
    {
        $draft = ContractDraft::find($draftId);
        if (!$draft) {
            return response()->json(['error' => 'Dossier introuvable.'], 404);
        }

        return response()->json([
            'status' => 'ok',
            'draft_id' => $draft->id,
            'flow_token' => $draft->flow_token,
            'statut' => $draft->statut,
            'pack' => $draft->pack,
            'prix' => $draft->prix,
            'signer_email' => $draft->signer_email,
            'payer_email' => $draft->payer_email,
            'contrat_data' => $draft->contrat_data,
            'hash_brouillon' => $draft->hash_brouillon,
            'hash_sha256' => $draft->hash_sha256,
            'signed_pdf_path' => $draft->signed_pdf_path,
            'ip_address' => $draft->ip_address,
            'user_agent' => $draft->user_agent,
            'session_id' => $draft->session_id,
            'paiement_transaction_id' => $draft->paiement_transaction_id,
            'otp_verified_at' => $draft->otp_verified_at,
            'created_at' => $draft->created_at,
            'updated_at' => $draft->updated_at,
        ]);
    }

    /** POST /api/v1/souscription-nouveau-flux/{draftId}/approve — Étape 2 : lecture du contrat + approbation */
    public function approveDraft(Request $request, int $draftId): JsonResponse
    {
        $input = $request->validate([
            'approuve' => 'required|boolean',
        ]);

        $draft = ContractDraft::find($draftId);
        if (!$draft) {
            return response()->json(['error' => 'Dossier introuvable.'], 404);
        }

        if (!$input['approuve']) {
            return response()->json(['error' => 'Vous devez approuver le contrat pour continuer.'], 400);
        }

        $brouillonPdf = $this->pdfService->generateContract(array_merge([
            'client_name' => $draft->contrat_data['nom'] ?? 'Client',
            'company_name' => $draft->contrat_data['entreprise'] ?? 'Entreprise',
            'price' => $draft->prix,
            'details' => $draft->contrat_data,
        ]));

        $hashBrouillon = hash('sha256', $brouillonPdf);
        Storage::disk('local')->put("drafts/brouillon_{$draft->id}.pdf", $brouillonPdf);

        $draft->update([
            'statut' => 'awaiting_signature',
            'hash_brouillon' => $hashBrouillon,
        ]);

        AuditLogger::log(
            'CONTRACT_BROUILLON_APPROUVE',
            (int) $draft->client_id,
            'client',
            json_encode([
                'flow_token' => $draft->flow_token,
                'hash_brouillon' => $hashBrouillon,
                'ip' => $request->ip(),
                'timestamp_utc' => now()->toIso8601String(),
            ])
        );

        return response()->json([
            'status' => 'approved',
            'statut' => 'awaiting_signature',
            'hash_brouillon' => $hashBrouillon,
            'message' => 'Contrat approuvé. Étape suivante : signature sur écran.',
        ]);
    }

    /** POST /api/v1/souscription-nouveau-flux/{draftId}/request-otp — Génère et envoie l'OTP de signature (24h) */
    public function requestSignatureOtp(Request $request, int $draftId): JsonResponse
    {
        $draft = ContractDraft::find($draftId);
        if (!$draft) {
            return response()->json(['error' => 'Dossier introuvable.'], 404);
        }

        if ($draft->statut !== 'awaiting_signature') {
            return response()->json(['error' => 'Le dossier doit être en statut AWAITING_SIGNATURE.'], 400);
        }

        // Générer l'OTP avec scope 'signature' et TTL 24h (86400s)
        $client = Client::find((int) $draft->client_id);
        if (!$client) {
            return response()->json(['error' => 'Client introuvable.'], 404);
        }

        $otp = OtpService::generate((int) $draft->client_id, 'signature', self::OTP_SIGNATURE_TTL);

        $otpDelivered = false;
        try {
            $otpDelivered = $this->notifier->sendSignatureOtp($draft->signer_email, $otp);
        } catch (\Throwable $mailEx) {
            Log::warning('Échec envoi OTP signature: ' . $mailEx->getMessage());
        }

        AuditLogger::log(
            'CONTRACT_OTP_SIGNATURE_ENVoye',
            (int) $draft->client_id,
            'client',
            json_encode([
                'flow_token' => $draft->flow_token,
                'email' => $draft->signer_email,
                'ip' => $request->ip(),
                'timestamp_utc' => now()->toIso8601String(),
                'ttl_secondes' => self::OTP_SIGNATURE_TTL,
            ])
        );

        return response()->json([
            'status' => 'otp_sent',
            'email' => $draft->signer_email,
            'ttl_secondes' => self::OTP_SIGNATURE_TTL,
            'message' => 'Code OTP envoyé par e-mail. Valable 24 heures.',
        ]);
    }

    /** POST /api/v1/souscription-nouveau-flux/{draftId}/sign — Étape 3 : signature OpenSign + OTP */
    public function signDraft(Request $request, int $draftId): JsonResponse
    {
        $input = $request->validate([
            'otp' => 'required|string|size:6',
            'signature_image' => 'nullable|string',
            'signature_metadata' => 'nullable|array',
        ]);

        $draft = ContractDraft::find($draftId);
        if (!$draft) {
            return response()->json(['error' => 'Dossier introuvable.'], 404);
        }

        if ($draft->statut !== 'awaiting_signature') {
            return response()->json(['error' => 'Le dossier doit être en statut AWAITING_SIGNATURE.'], 400);
        }

        if (!OtpService::verify((int) $draft->client_id, $input['otp'], 'signature')) {
            return response()->json(['error' => 'Code OTP invalide ou expiré.'], 400);
        }

        $finalPdf = $this->pdfService->generateContract(array_merge([
            'client_name' => $draft->contrat_data['nom'] ?? 'Client',
            'company_name' => $draft->contrat_data['entreprise'] ?? 'Entreprise',
            'price' => $draft->prix,
            'details' => $draft->contrat_data,
            'signature_image' => $input['signature_image'] ?? null,
        ]));

        if (!$this->opensign->isConfigured()) {
            return response()->json([
                'error' => 'OpenSign non configuré côté serveur.',
            ], 500);
        }

        try {
            $signResult = $this->opensign->signPdf([
                'document_data' => base64_encode($finalPdf),
                'signer_email' => $draft->signer_email,
                'signer_name' => $draft->contrat_data['representant'] ?? $draft->contrat_data['nom'],
                'signature_type' => 'canvas',
                'message' => 'Veuillez signer ce contrat de domiciliation',
            ]);
        } catch (\Exception $e) {
            Log::error('OpenSign signPdf error: ' . $e->getMessage());
            return response()->json([
                'error' => 'Échec de la signature électronique : ' . $e->getMessage(),
            ], 500);
        }

        $signedPdf = null;
        $documentId = $signResult['documentId'] ?? $signResult['id'] ?? null;
        if ($documentId) {
            try {
                $download = $this->opensign->downloadSignedDocument($documentId);
                if (isset($download['success']) && $download['success'] === true) {
                    $signedPdf = $download['content'] ?? $download['pdf_base64'] ?? null;
                }
            } catch (\Exception $e) {
                Log::warning('OpenSign download après signature échoué: ' . $e->getMessage());
            }
        }

        if (!$signedPdf) {
            $signedPdf = $finalPdf;
        }

        $hashFinal = hash('sha256', $signedPdf);
        $signedPath = "contrats/signe_{$draft->id}.pdf";
        Storage::disk('local')->put($signedPath, $signedPdf);

        // Chaînage cryptographique du journal d'audit
        $previousEntry = DB::table('audit_log')
            ->where('user_id', $draft->client_id)
            ->where('action', 'CONTRACT_BROUILLON_APPROUVE')
            ->orderByDesc('timestamp')
            ->first();
        $previousHash = $previousEntry ? hash('sha256', $previousEntry->details . $previousEntry->timestamp) : '';
        $currentPayload = json_encode([
            'flow_token' => $draft->flow_token,
            'hash_sha256' => $hashFinal,
            'hash_brouillon' => $draft->hash_brouillon,
            'ip_signature' => $request->ip(),
            'otp_verifie' => true,
            'timestamp_signature_utc' => now()->toIso8601String(),
        ]);
        $currentHash = hash('sha256', ($previousHash ?? '') . $currentPayload);

        $draft->update([
            'statut' => 'signed_awaiting_payment',
            'hash_sha256' => $hashFinal,
            'signed_pdf_path' => $signedPath,
            'otp_verified_at' => now(),
        ]);

        AuditLogger::log(
            'CONTRACT_SIGNE_SCelle',
            (int) $draft->client_id,
            'client',
            json_encode(array_merge(
                json_decode($currentPayload, true),
                [
                    'previous_hash' => $previousHash,
                    'current_hash' => $currentHash,
                ]
            ))
        );

        OtpService::clear((int) $draft->client_id, 'signature');

        return response()->json([
            'status' => 'signed',
            'statut' => 'signed_awaiting_payment',
            'hash_sha256' => $hashFinal,
            'message' => 'Contrat signé et scellé. Étape suivante : paiement.',
        ]);
    }

    /** POST /api/v1/souscription-nouveau-flux/{draftId}/pay — Étape 4 : paiement Campay */
    public function processPayment(Request $request, int $draftId): JsonResponse
    {
        $draft = ContractDraft::find($draftId);
        if (!$draft) {
            return response()->json(['error' => 'Dossier introuvable.'], 404);
        }

        if ($draft->statut !== 'signed_awaiting_payment') {
            return response()->json(['error' => 'Le contrat doit être signé avant le paiement.'], 400);
        }

        $campayEnabled = FedpawaConfig::bool('CAMPAY_ENABLED', true);
        $transactionId = null;

        if ($campayEnabled && $draft->prix > 0) {
            try {
                $client = Client::find((int) $draft->client_id);
                $phone = $draft->contrat_data['telephone'] ?? ($client?->telephone ?? '');
                $payment = $this->campay->collect((float) $draft->prix, (string) $phone, 'DRAFT_' . $draft->id);
                $transactionId = $payment['reference'] ?? $payment['transaction_id'] ?? null;
            } catch (\Exception $e) {
                Log::error('Campay initiation error: ' . $e->getMessage());
                return response()->json([
                    'error' => 'Impossible d\'initier le paiement. Réessayez.',
                ], 502);
            }
        } elseif (!$campayEnabled) {
            $transactionId = 'DEMO-DRAFT-' . $draft->id;
        }

        $draft->update([
            'paiement_transaction_id' => $transactionId,
        ]);

        AuditLogger::log(
            'CONTRACT_PAIEMENT_INITIE',
            (int) $draft->client_id,
            'client',
            json_encode([
                'flow_token' => $draft->flow_token,
                'transaction_id' => $transactionId,
                'montant' => $draft->prix,
                'ip' => $request->ip(),
                'timestamp_utc' => now()->toIso8601String(),
            ])
        );

        return response()->json([
            'status' => 'payment_initiated',
            'statut' => 'signed_awaiting_payment',
            'transaction_id' => $transactionId,
            'montant' => $draft->prix,
            'message' => 'Paiement initié. Une fois confirmé, le dossier passe en COMPLETED.',
        ]);
    }

    /** POST /api/v1/souscription-nouveau-flux/webhook-campay — Webhook Campay (confirmation paiement) */
    public function confirmPayment(Request $request): JsonResponse
    {
        $hmac = $request->header('X-Campay-Signature') ?? $request->input('signature');
        $payload = $request->all();

        if (!$this->verifyCampayHmac($payload, $hmac)) {
            return response()->json(['error' => 'Signature HMAC invalide.'], 401);
        }

        $flowToken = $payload['flow_token'] ?? ($payload['metadata'] ?? null);
        $transactionId = $payload['transaction_id'] ?? $payload['reference'] ?? null;
        $statut = $payload['statut'] ?? $payload['status'] ?? 'pending';

        if (!$flowToken) {
            return response()->json(['error' => 'flow_token manquant.'], 400);
        }

        $draft = ContractDraft::where('flow_token', $flowToken)->first();
        if (!$draft) {
            return response()->json(['error' => 'Dossier introuvable pour ce flow_token.'], 404);
        }

        if ($statut === 'SUCCESSFUL' || $statut === 'recu' || $statut === 'paid') {
            $draft->update([
                'statut' => 'completed',
                'paiement_transaction_id' => $transactionId,
            ]);

            AuditLogger::log(
                'CONTRACT_PAIEMENT_CONFIRME',
                (int) $draft->client_id,
                'client',
                json_encode([
                    'flow_token' => $draft->flow_token,
                    'transaction_id' => $transactionId,
                    'montant' => $draft->prix,
                    'statut_final' => 'completed',
                    'timestamp_utc' => now()->toIso8601String(),
                ])
            );

            $this->sendProofEmail($draft);
            $this->notifyFedpawaInternal($draft);

            $this->push->notifyEvent(
                (int) $draft->client_id,
                'contrat_completed',
                'Souscription finalisée',
                'Votre contrat est signé et votre paiement est confirmé. Votre service est activé.',
                '/espace-client?tab=contrats'
            );

            return response()->json([
                'status' => 'completed',
                'message' => 'Paiement confirmé. Contrat signé et service activé.',
            ]);
        }

        return response()->json([
            'status' => 'payment_pending',
            'message' => 'Paiement en attente de confirmation.',
        ]);
    }

    /** GET /api/v1/souscription-nouveau-flux/{draftId}/resume — Lien de reprise de paiement */
    public function resumePayment(int $draftId): JsonResponse
    {
        $draft = ContractDraft::find($draftId);
        if (!$draft) {
            return response()->json(['error' => 'Dossier introuvable.'], 404);
        }

        if ($draft->statut !== 'signed_awaiting_payment') {
            return response()->json(['error' => 'Ce dossier n\'est pas en attente de paiement.'], 404);
        }

        $campayEnabled = FedpawaConfig::bool('CAMPAY_ENABLED', true);
        $transactionId = null;

        if ($campayEnabled && $draft->prix > 0) {
            try {
                $client = Client::find((int) $draft->client_id);
                $phone = $draft->contrat_data['telephone'] ?? ($client?->telephone ?? '');
                $payment = $this->campay->collect((float) $draft->prix, (string) $phone, 'RESUME_DRAFT_' . $draft->id);
                $transactionId = $payment['reference'] ?? $payment['transaction_id'] ?? null;
            } catch (\Exception $e) {
                return response()->json(['error' => 'Impossible de reprendre le paiement.'], 502);
            }
        } elseif (!$campayEnabled) {
            $transactionId = 'DEMO-RESUME-' . $draft->id;
        }

        $draft->update(['paiement_transaction_id' => $transactionId]);

        AuditLogger::log(
            'CONTRACT_PAIEMENT_REPRISE',
            (int) $draft->client_id,
            'client',
            json_encode([
                'flow_token' => $draft->flow_token,
                'transaction_id' => $transactionId,
                'ip' => request()->ip(),
                'timestamp_utc' => now()->toIso8601String(),
            ])
        );

        return response()->json([
            'status' => 'payment_resumed',
            'transaction_id' => $transactionId,
            'montant' => $draft->prix,
            'message' => 'Lien de reprise généré. Procédez au paiement.',
        ]);
    }

    /** Envoi de l'email avec le PDF signé + fiche récapitulative de preuve (Étape 5) */
    private function sendProofEmail(ContractDraft $draft): void
    {
        $client = Client::find((int) $draft->client_id);
        if (!$client) {
            return;
        }

        $proofPdf = $this->generateProofPdf($draft, $client);

        try {
            $this->notifier->sendEventEmail(
                $client,
                'contrat_signe_paiement_confirme',
                'Votre contrat signé et preuve de souscription — FEDPAWA',
                'emails.14-contrat-signe-paiement',
                [
                    'client_nom' => $client->nom,
                    'dossier_id' => $draft->id,
                    'flow_token' => $draft->flow_token,
                    'pack' => $draft->pack,
                    'montant_contrat' => number_format($draft->prix, 0, ',', ' ') . ' FCFA',
                    'montant_paye' => number_format($draft->prix, 0, ',', ' ') . ' FCFA',
                    'transaction_id' => $draft->paiement_transaction_id,
                    'ip_signature' => $draft->ip_address,
                    'horodatage_signature' => $draft->otp_verified_at?->format('d/m/Y H:i:s') ?? '—',
                    'hash_sha256' => $draft->hash_sha256,
                    'lien_espace_client' => config('app.url', 'https://www.fedpawa.com') . '/espace-client?tab=contrats',
                ]
            );
        } catch (\Throwable $e) {
            Log::error('Échec envoi email de preuve: ' . $e->getMessage());
        }
    }

    /** Notification interne FEDPAWA à la completion (Étape 5) */
    private function notifyFedpawaInternal(ContractDraft $draft): void
    {
        try {
            $this->push->notifyEvent(
                0,
                'nouveau_contrat_signe',
                'Nouveau contrat signé — ' . $draft->pack,
                'Un client a signé et payé un contrat de domiciliation. Dossier #' . $draft->id,
                '/admin/contrats'
            );
        } catch (\Throwable $e) {
            Log::warning('Échec notification interne FEDPAWA: ' . $e->getMessage());
        }
    }

    /** Recalcule le prix officiel côté serveur. */
    private function prixServeur(string $pack, ?string $periodicite = null): float
    {
        $prixMensuel = self::PACKS_SERVER[$pack] ?? 0.0;
        $mois = ['mensuel' => 1, 'semestriel' => 6, 'annuel' => 12][$periodicite ?? 'mensuel'] ?? 1;
        return (float) $prixMensuel * (float) $mois;
    }

    /** Vérification HMAC Campay (best-effort). */
    private function verifyCampayHmac(array $payload, ?string $hmac): bool
    {
        if (!$hmac) {
            return false;
        }
        $secret = FedpawaConfig::get('CAMPAY_APP_SECRET', '');
        if (!$secret) {
            return true;
        }
        $expected = hash_hmac('sha256', json_encode($payload), $secret);
        return hash_equals($expected, $hmac);
    }

    /** Génère le PDF de la fiche récapitulative de preuve. */
    private function generateProofPdf(ContractDraft $draft, Client $client): string
    {
        $html = '<h1>Fiche récapitulative de preuve — FEDPAWA</h1>';
        $html .= '<p><strong>Client :</strong> ' . e($client->nom) . '</p>';
        $html .= '<p><strong>E-mail vérifié :</strong> ' . e($draft->signer_email) . '</p>';
        $html .= '<p><strong>Forfait souscrit :</strong> ' . e($draft->pack) . '</p>';
        $html .= '<p><strong>Montant du contrat :</strong> ' . number_format($draft->prix, 0, ',', ' ') . ' FCFA</p>';
        $html .= '<p><strong>Montant payé :</strong> ' . number_format($draft->prix, 0, ',', ' ') . ' FCFA</p>';
        $html .= '<p><strong>Référence de transaction :</strong> ' . e($draft->paiement_transaction_id ?? '—') . '</p>';
        $html .= '<p><strong>Adresse IP de signature :</strong> ' . e($draft->ip_address ?? '—') . '</p>';
        $html .= '<p><strong>Horodatage de signature :</strong> ' . ($draft->otp_verified_at?->format('d/m/Y H:i:s') ?? '—') . '</p>';
        $html .= '<p><strong>Hash SHA-256 du contrat :</strong> ' . e($draft->hash_sha256 ?? '—') . '</p>';
        $html .= '<p><strong>Statut :</strong> ' . e($draft->statut) . '</p>';

        $options = new Options;
        $options->setDefaultFont('Helvetica');
        $options->setIsPhpEnabled(false);
        $options->setIsRemoteEnabled(false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /** POST /api/v1/souscription-nouveau-flux/{draftId}/email-link — Collecte de l'email dédié */
    public function collectEmail(Request $request, int $draftId): JsonResponse
    {
        $input = $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $draft = ContractDraft::find($draftId);
        if (!$draft) {
            return response()->json(['error' => 'Dossier introuvable.'], 404);
        }

        // Mettre à jour l'email payeur/dédié
        $draft->update([
            'payer_email' => $input['email'],
            'statut' => 'awaiting_email',
        ]);

        // Générer un jeton de signature à usage unique (30 minutes)
        $signatureToken = bin2hex(random_bytes(16));
        $cacheKey = 'signature_token:' . $signatureToken;
        Cache::put($cacheKey, [
            'draft_id' => $draft->id,
            'created_at' => now()->toIso8601String(),
        ], now()->addMinutes(30));

        $signatureLink = config('app.url', 'https://www.fedpawa.com') . '/souscription-nouveau-flux/signature/' . $signatureToken;

        AuditLogger::log(
            'CONTRACT_EMAIL_COLLECTE',
            (int) $draft->client_id,
            'client',
            json_encode([
                'flow_token' => $draft->flow_token,
                'payer_email' => $input['email'],
                'signature_token' => $signatureToken,
                'ip' => $request->ip(),
                'timestamp_utc' => now()->toIso8601String(),
            ])
        );

        return response()->json([
            'status' => 'email_collecte',
            'flow_token' => $draft->flow_token,
            'signature_link' => $signatureLink,
            'message' => 'E-mail enregistré. Lien de signature généré.',
        ]);
    }

    /** POST /api/v1/souscription-nouveau-flux/{draftId}/payment-link — Envoi du lien de paiement (après signature) */
    public function sendPaymentLink(Request $request, int $draftId): JsonResponse
    {
        $draft = ContractDraft::find($draftId);
        if (!$draft) {
            return response()->json(['error' => 'Dossier introuvable.'], 404);
        }

        if ($draft->statut !== 'signed_awaiting_payment') {
            return response()->json(['error' => 'Le contrat doit être signé avant l\'envoi du lien de paiement.'], 400);
        }

        // Générer un jeton de paiement à usage unique (30 minutes)
        $paymentToken = bin2hex(random_bytes(16));
        $cacheKey = 'payment_token:' . $paymentToken;
        Cache::put($cacheKey, [
            'draft_id' => $draft->id,
            'created_at' => now()->toIso8601String(),
        ], now()->addMinutes(30));

        $paymentLink = config('app.url', 'https://www.fedpawa.com') . '/souscription-nouveau-flux/paiement/' . $paymentToken;

        AuditLogger::log(
            'CONTRACT_PAYMENT_LINK_GENERE',
            (int) $draft->client_id,
            'client',
            json_encode([
                'flow_token' => $draft->flow_token,
                'payment_token' => $paymentToken,
                'montant' => $draft->prix,
                'ip' => $request->ip(),
                'timestamp_utc' => now()->toIso8601String(),
            ])
        );

        return response()->json([
            'status' => 'payment_link_genere',
            'payment_link' => $paymentLink,
            'montant' => $draft->prix,
            'message' => 'Lien de paiement généré. Valable 30 minutes.',
        ]);
    }

    /** POST /api/v1/souscription-nouveau-flux/payment-link/consume — Consommation du jeton de paiement */
    public function consumePaymentLink(Request $request): JsonResponse
    {
        $input = $request->validate([
            'token' => 'required|string',
        ]);

        $cacheKey = 'payment_token:' . $input['token'];
        $data = Cache::get($cacheKey);
        if (!$data) {
            return response()->json(['error' => 'Lien de paiement invalide, expiré ou déjà utilisé.'], 410);
        }

        // Consommer le jeton (usage unique)
        Cache::forget($cacheKey);

        $draft = ContractDraft::find($data['draft_id']);
        if (!$draft) {
            return response()->json(['error' => 'Dossier introuvable.'], 404);
        }

        return response()->json([
            'status' => 'token_consomme',
            'draft_id' => $draft->id,
            'flow_token' => $draft->flow_token,
            'montant' => $draft->prix,
            'redirect_url' => config('app.url', 'https://www.fedpawa.com') . '/espace-client?tab=paiement&draft=' . $draft->id,
            'message' => 'Jeton de paiement consommé. Redirection vers le paiement.',
        ]);
    }

    /** POST /api/v1/souscription-nouveau-flux/signature-link/consume — Consommation du jeton de signature */
    public function consumeSignatureLink(Request $request): JsonResponse
    {
        $input = $request->validate([
            'token' => 'required|string',
        ]);

        $cacheKey = 'signature_token:' . $input['token'];
        $data = Cache::get($cacheKey);
        if (!$data) {
            return response()->json(['error' => 'Lien de signature invalide, expiré ou déjà utilisé.'], 410);
        }

        // Consommer le jeton (usage unique)
        Cache::forget($cacheKey);

        $draft = ContractDraft::find($data['draft_id']);
        if (!$draft) {
            return response()->json(['error' => 'Dossier introuvable.'], 404);
        }

        return response()->json([
            'status' => 'token_consomme',
            'draft_id' => $draft->id,
            'flow_token' => $draft->flow_token,
            'redirect_url' => config('app.url', 'https://www.fedpawa.com') . '/espace-client?tab=signature&draft=' . $draft->id,
            'message' => 'Jeton de signature consommé. Redirection vers la signature.',
        ]);
    }
}
