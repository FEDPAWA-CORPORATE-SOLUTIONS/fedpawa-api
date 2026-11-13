<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Contrat;
use App\Services\NotificationService;
use App\Services\OpenSignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Contrôleur d'intégration OpenSign (signature électronique).
 *
 * Endpoints (sous /api/v1/opensign, auth:sanctum requis) :
 * - POST  /signer          — envoyer un contrat à signer via OpenSign
 * - GET   /status/{id}     — consulter le statut d'une enveloppe de signature
 * - GET   /download/{id}   — télécharger le PDF signé + certificat de completion
 * - POST  /refresh-token   — forcer le renouvellement du token OpenSign (admin)
 *
 * Le session token OpenSign est géré uniquement côté backend par OpenSignService.
 * Le frontend n'a jamais accès à ce token.
 */
class OpenSignController extends Controller
{
    public function __construct(
        private OpenSignService $opensign,
        private NotificationService $notifier
    ) {}

    /**
     * Envoyer un contrat à signature électronique.
     *
     * Body attendu :
     * {
     *   "document_id": "string",       // identifiant interne de votre contrat
     *   "document_url": "string",      // URL du PDF à signer (ou base64 selon votre implémentation OpenSign)
     *   "signers": [
     *     { "email": "client@example.com", "name": "Jean Dupont", "role": "signataire" }
     *   ],
     *   "message": "Veuillez signer ce contrat"
     * }
     */
    public function signer(Request $request): JsonResponse
    {
        $request->validate([
            'document_id' => 'required|string',
            'document_url' => 'required|url',
            'signers' => 'required|array|min:1',
            'signers.*.email' => 'required|email',
            'signers.*.name' => 'required|string',
            'signers.*.role' => 'nullable|string',
            'message' => 'nullable|string',
        ]);

        if (!$this->opensign->isConfigured()) {
            return response()->json([
                'success' => false,
                'error' => 'OpenSign non configuré (variables OPENSIGN_SERVICE_EMAIL / OPENSIGN_SERVICE_PASSWORD manquantes)',
            ], 500);
        }

        try {
            $payload = [
                'document_id' => $request->string('document_id'),
                'document_url' => $request->string('document_url'),
                'signers' => $request->array('signers'),
                'message' => $request->string('message') ?? 'Veuillez signer ce document',
                'created_by' => $request->user()?->id,
            ];

            $result = $this->opensign->createEnvelope($payload);

            // Lier l'enveloppe OpenSign au contrat (document_id = id du contrat)
            $contratId = (int) $request->string('document_id');
            if ($contratId > 0) {
                $envelopeId = $result['envelopeId'] ?? $result['id'] ?? $result['objectId'] ?? $result['envelope_id'] ?? null;
                if ($envelopeId) {
                    DB::table('contrats')->where('id', $contratId)->update([
                        'envelope_id' => (string) $envelopeId,
                    ]);
                }
            }

            Log::info('OpenSign: envelope created for document '.$request->string('document_id'), [
                'envelope' => $result,
            ]);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('OpenSign signer error: '.$e->getMessage());

            // Notifier le client si le contrat est identifiable
            $contratId = (int) $request->string('document_id');
            if ($contratId > 0) {
                $contrat = DB::table('contrats')->where('id', $contratId)->first(['client_id']);
                if ($contrat) {
                    $client = Client::find((int) $contrat->client_id);
                    if ($client) {
                        $this->notifier->sendEventEmail(
                            $client,
                            'contrat_scelle',
                            'Échec de la signature électronique de votre contrat — FEDPAWA',
                            'emails.07-contrat-scelle',
                            [
                                'dossier_id' => $contratId,
                                'date_scelle' => now()->format('d/m/Y H:i'),
                                'empreinte_sha256' => '—',
                                'lien_preuve_pdf' => config('app.url', 'https://app.fedpawa.com').'/espace-client?tab=documents',
                                '_erreur' => $e->getMessage(),
                            ]
                        );
                    }
                }
            }

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Consulter le statut d'une enveloppe de signature.
     *
     * @param  string  $envelopeId
     */
    public function status(string $envelopeId): JsonResponse
    {
        try {
            $result = $this->opensign->getEnvelopeStatus($envelopeId);

            // Détecter si l'enveloppe est scellée (statut terminal côté OpenSign)
            $statutOpenSign = (string) (
                $result['status'] ?? $result['statut'] ?? $result['etat'] ?? $result['state'] ?? ''
            );
            $estScelle = preg_match(
                '/(completed|signed|sealed|scell.|termin.|done|finalis.)/i',
                $statutOpenSign
            ) === 1;

            if ($estScelle) {
                // Lier l'enveloppe au contrat et mettre à jour la date de scellement
                $contrat = DB::table('contrats')->where('envelope_id', $envelopeId)->first(['id', 'client_id']);
                if ($contrat) {
                    DB::table('contrats')->where('id', (int) $contrat->id)->update([
                        'date_scelle' => now()->format('Y-m-d H:i:s'),
                    ]);

                    // E-mail événementiel : contrat scellé (gabarit 07)
                    $client = Client::find((int) $contrat->client_id);
                    if ($client) {
                        $this->notifier->sendEventEmail(
                            $client,
                            'contrat_scelle',
                            'Votre contrat est scellé — dossier #'.(int) $contrat->id.' — FEDPAWA',
                            'emails.07-contrat-scelle',
                            [
                                'dossier_id' => (int) $contrat->id,
                                'date_scelle' => now()->format('d/m/Y H:i'),
                                'empreinte_sha256' => $result['empreinte_sha256'] ?? $result['hash'] ?? '—',
                                'lien_preuve_pdf' => config('app.url', 'https://app.fedpawa.com').'/espace-client?tab=documents',
                            ]
                        );
                    }
                }
            }

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('OpenSign status error: '.$e->getMessage());

            // Notifier le client si le contrat est identifiable via l'enveloppe
            $contrat = DB::table('contrats')->where('envelope_id', $envelopeId)->first(['id', 'client_id']);
            if ($contrat) {
                $client = Client::find((int) $contrat->client_id);
                if ($client) {
                    $this->notifier->sendEventEmail(
                        $client,
                        'contrat_scelle',
                        'Échec de la signature électronique de votre contrat — FEDPAWA',
                        'emails.07-contrat-scelle',
                        [
                            'dossier_id' => (int) $contrat->id,
                            'date_scelle' => now()->format('d/m/Y H:i'),
                            'empreinte_sha256' => '—',
                            'lien_preuve_pdf' => config('app.url', 'https://app.fedpawa.com').'/espace-client?tab=documents',
                            '_erreur' => $e->getMessage(),
                        ]
                    );
                }
            }

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Télécharger le document signé (PDF scellé + certificat de completion).
     *
     * @param  string  $documentId
     */
    public function download(string $documentId): JsonResponse
    {
        try {
            $result = $this->opensign->downloadSignedDocument($documentId);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('OpenSign download error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Forcer le renouvellement du token OpenSign (admin uniquement).
     */
    public function refreshToken(): JsonResponse
    {
        $user = auth()->user();
        if (!$user || !$user->can('admin.access')) {
            return response()->json([
                'success' => false,
                'error' => 'Accès admin requis',
            ], 403);
        }

        if (!$this->opensign->isConfigured()) {
            return response()->json([
                'success' => false,
                'error' => 'OpenSign non configuré',
            ], 500);
        }

        try {
            $result = $this->opensign->authenticate();

            return response()->json([
                'success' => true,
                'message' => 'Token OpenSign renouvelé',
                'expires_at' => $result['expires_at']->toDateTimeString(),
            ]);
        } catch (\Exception $e) {
            Log::error('OpenSign refresh token error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
