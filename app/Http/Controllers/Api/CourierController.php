<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CourierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Parité de src/Controller/CourierController.php :
 * - POST /api/v1/courrier/upload : client OU admin, fichiers multiples
 *   ('documents[]'), conversion image->PDF + fusion, réponse
 *   {'message': 'Upload et conversion multi-pages réussis', 'path': ...} ;
 * - service CourierService (voir app/Services/CourierService.php).
 */
class CourierController extends Controller
{
    public function __construct(private CourierService $courier) {}

    /** POST /api/v1/courrier/upload */
    public function upload(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Non autorisé'], 403);
        }
        $client = DB::table('clients')->where('email', $user->email)->first();
        if (! $client) {
            return response()->json(['error' => 'Non autorisé'], 403);
        }

        $rawFiles = $request->file('documents') ?: [];
        // Normalisation : 'documents' peut être un fichier unique ou un tableau
        $files = $rawFiles instanceof \Illuminate\Http\UploadedFile ? [$rawFiles] : array_values(array_filter($rawFiles, fn ($f) => $f instanceof \Illuminate\Http\UploadedFile));
        $isAdmin = ($client->role ?? 'client') === 'admin';
        // Parité source : $_POST['client_id'] ?? session client_id
        $clientId = (int) ($request->input('client_id') ?: $client->id);
        if (! $isAdmin && $clientId !== (int) $client->id) {
            return response()->json(['error' => 'Non autorisé'], 403);
        }

        if (empty($files)) {
            return response()->json(['error' => 'Aucun fichier'], 400);
        }

        try {
            $path = $this->courier->uploadMultiple($clientId, array_values($files));

            return response()->json([
                'message' => 'Upload et conversion multi-pages réussis',
                'path' => $path,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}
