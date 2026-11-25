<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PortantSignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Contrôleur d'intégration Portant Sign (signature électronique).
 *
 * Endpoints (sous /api/v1/portant-sign, auth:sanctum requis) :
 * - POST  /portant-sign/send          — envoyer un contrat à signer via Portant
 * - GET   /portant-sign/status/{id}   — consulter le statut d'une demande de signature
 * - GET   /portant-sign/download/{id} — télécharger le PDF signé
 *
 * Le service Portant nécessite une clé API (PORTANT_API_KEY).
 * Le fichier à signer est transmis soit par chemin local, soit par base64.
 */
class PortantSignController extends Controller
{
    public function __construct(private PortantSignService $portant) {}

    /**
     * POST /api/v1/portant-sign/send
     *
     * Body attendu (multipart ou JSON) :
     * {
     *   "signer_name": "string",       // requis : nom du signataire
     *   "signer_email": "string",      // requis : email du signataire
     *   "sender_email": "string",      // email de l'utilisateur demandeur
     *   "title": "string",             // titre du document
     *   "message": "string",           // message accompagnant
     *   "file_path": "string",         // chemin local du fichier OU
     *   "file_base64": "string",       // contenu du fichier en base64
     *   "file_name": "string"          // nom du fichier (si base64)
     * }
     */
    public function send(Request $request): JsonResponse
    {
        if (! $this->portant->isConfigured()) {
            return response()->json([
                'success' => false,
                'error' => 'Portant non configuré (variable PORTANT_API_KEY manquante)',
            ], 500);
        }

        $validated = $request->validate([
            'signer_name' => 'required|string|max:255',
            'signer_email' => 'required|email|max:255',
            'sender_email' => 'nullable|email|max:255',
            'title' => 'nullable|string|max:255',
            'message' => 'nullable|string|max:1000',
            'file_path' => 'nullable|string|exists:storage,path',
            'file_base64' => 'nullable|string',
            'file_name' => 'nullable|string|max:255',
        ]);

        $filePath = $validated['file_path'] ?? null;
        $fileBase64 = $validated['file_base64'] ?? null;
        $fileName = $validated['file_name'] ?? 'contrat.pdf';

        // Si un chemin local est fourni, lire le fichier et le convertir en base64
        if ($filePath && ! $fileBase64) {
            $fullPath = storage_path('app/' . $filePath);
            if (! file_exists($fullPath)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Fichier introuvable : ' . $filePath,
                ], 404);
            }
            $fileBase64 = base64_encode(file_get_contents($fullPath));
            $fileName = basename($fullPath);
        }

        if (! $fileBase64) {
            return response()->json([
                'success' => false,
                'error' => 'Aucun fichier fourni (file_path ou file_base64 requis)',
            ], 400);
        }

        $senderEmail = $validated['sender_email'] ?? $request->user()?->email ?? '';

        $result = $this->portant->sendForSignature([
            'title' => $validated['title'] ?? 'Contrat à signer — ' . $fileName,
            'pdf_base64' => $fileBase64,
            'signer_name' => $validated['signer_name'],
            'signer_email' => $validated['signer_email'],
            'sender_email' => $senderEmail,
            'message' => $validated['message'] ?? null,
            'language' => 'fr',
            'sender_signs' => false,
            'certificate' => true,
        ]);

        if (! $result['success']) {
            Log::error('Portant send error: ' . ($result['error'] ?? 'inconnu'));

            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Erreur inconnue lors de l\'envoi à Portant',
                'document_hash' => $result['document_hash'] ?? null,
            ], 502);
        }

        return response()->json([
            'success' => true,
            'request_id' => $result['request_id'],
            'request_token' => $result['request_token'],
            'next_step' => $result['next_step'],
            'document_hash' => $result['document_hash'],
            'api_response' => $result['api_response'],
        ]);
    }

    /**
     * GET /api/v1/portant-sign/status/{id}
     */
    public function status(string $id): JsonResponse
    {
        $result = $this->portant->getStatus($id);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Erreur inconnue',
            ], 502);
        }

        return response()->json([
            'success' => true,
            'request_id' => $id,
            'status' => $result['status'],
            'data' => $result['data'],
        ]);
    }

    /**
     * GET /api/v1/portant-sign/download/{id}
     *
     * Télécharge le PDF signé depuis Portant et le stocke localement.
     * Retourne le chemin du fichier stocké et son contenu en base64.
     */
    public function download(string $id, Request $request): JsonResponse
    {
        $requestToken = $request->query('request_token', '');

        $result = $this->portant->getSignedPdf($id, $requestToken);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Erreur inconnue lors du téléchargement',
            ], 502);
        }

        $content = $result['content'];
        $contentType = $result['content_type'] ?? 'application/pdf';
        $documentHash = $result['document_hash'];

        // Stocker le PDF signé localement pour traçabilité
        $fileName = 'signed_contract_' . $id . '.pdf';
        $storedPath = 'signed_contracts/' . $fileName;
        Storage::disk('local')->put($storedPath, $content);

        return response()->json([
            'success' => true,
            'request_id' => $id,
            'document_hash' => $documentHash,
            'content_type' => $contentType,
            'stored_path' => $storedPath,
            'download_url' => '/api/v1/portant-sign/file/' . $id,
            'content_base64' => base64_encode($content),
        ]);
    }

    /**
     * GET /api/v1/portant-sign/file/{id}
     *
     * Sert le fichier PDF signé stocké localement.
     */
    public function file(string $id): JsonResponse
    {
        $fileName = 'signed_contract_' . $id . '.pdf';
        $storedPath = 'signed_contracts/' . $fileName;

        if (! Storage::disk('local')->exists($storedPath)) {
            return response()->json([
                'success' => false,
                'error' => 'Fichier signé introuvable',
            ], 404);
        }

        $content = Storage::disk('local')->get($storedPath);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $fileName . '"',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }
}
