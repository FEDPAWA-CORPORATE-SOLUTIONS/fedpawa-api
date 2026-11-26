<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FileUploader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/v1/souscription/upload : upload de fichiers pour souscription
 * (pièces KYC, statuts, contrat, etc.) — client authentifié uniquement.
 *
 * Body (multipart/form-data) :
 *   - file : UploadedFile (un seul fichier par appel)
 *   - client_id : optionnel, sinon déduit de l'utilisateur connecté
 *
 * Retourne : { ok: true, path: 'client_<id>/doc_<uniqid>_<nom>' }
 */
class SubscriptionFileController extends Controller
{
    public function __construct(private FileUploader $uploader) {}

    public function upload(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['error' => 'Non autorisé'], 403);
            }

            $client = DB::table('clients')->where('email', $user->email)->first();
            if (!$client) {
                return response()->json(['error' => 'Non autorisé'], 403);
            }

            $file = $request->file('file');
            if (!$file || !$file->isValid()) {
                return response()->json(['error' => 'Aucun fichier valide'], 400);
            }

            $clientId = (int) ($client->id);
            $clientFolder = 'client_'.$clientId;

            $path = $this->uploader->upload($file, $clientFolder);

            return response()->json([
                'ok' => true,
                'path' => $path,
                'message' => 'Fichier uploadé avec succès.',
            ]);
        } catch (\Throwable $e) {
            Log::error('Upload fichier souscription échoué: '.$e->getMessage(), ['exception' => get_class($e), 'trace' => $e->getTraceAsString()]);

            return response()->json(['error' => 'Erreur upload: '.$e->getMessage()], 500);
        }
    }
}
