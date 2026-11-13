<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OpenSignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        private OpenSignService $opensign
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

            Log::info('OpenSign: envelope created for document '.$request->string('document_id'), [
                'envelope' => $result,
            ]);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('OpenSign signer error: '.$e->getMessage());

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

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('OpenSign status error: '.$e->getMessage());

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
