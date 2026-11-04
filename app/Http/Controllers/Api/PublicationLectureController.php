<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesClientId;
use App\Http\Controllers\Controller;
use App\Models\Publication;
use App\Models\PublicationLecture;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Endpoints CLIENT (auth:sanctum) — CONTRAT-PUBLICATIONS.md §3, cloche :
 * - GET  /api/v1/publications/non-lues  : {nombre, ids} des publiées non lues ;
 * - POST /api/v1/publications/{id}/lu   : marquage lu, idempotent (unicité) ;
 * - POST /api/v1/publications/tout-lu    : marque toutes les publiées comme lues.
 * Le « marquage automatique à l'ouverture » côté frontend s'appuie sur POST /lu.
 */
class PublicationLectureController extends Controller
{
    use ResolvesClientId;

    /** GET /api/v1/publications/non-lues (auth client) */
    public function nonLues(Request $request): JsonResponse
    {
        $clientId = $this->clientId($request);
        if ($clientId === null) {
            return response()->json(['error' => 'Client introuvable'], 404);
        }

        $ids = DB::table('publications')
            ->where('statut', 'publie')
            ->whereNotNull('publie_le')
            ->where('publie_le', '<=', now())
            ->whereNotIn('id', function ($q) use ($clientId) {
                $q->select('publication_id')
                    ->from('publication_lectures')
                    ->where('client_id', $clientId);
            })
            ->orderByDesc('publie_le')
            ->orderByDesc('id')
            ->pluck('id')
            ->map(fn ($i) => (int) $i)
            ->all();

        return response()->json([
            'nombre' => count($ids),
            'ids' => $ids,
        ]);
    }

    /** POST /api/v1/publications/{id}/lu (auth client) — idempotent. */
    public function marquerLu(Request $request, int $id): JsonResponse
    {
        $clientId = $this->clientId($request);
        if ($clientId === null) {
            return response()->json(['error' => 'Client introuvable'], 404);
        }

        $publication = Publication::find($id);
        if (! $publication || ! $publication->estPubliee()) {
            return response()->json(['error' => 'Publication introuvable'], 404);
        }

        PublicationLecture::firstOrCreate(
            ['publication_id' => $publication->id, 'client_id' => $clientId],
            ['lu_le' => now()]
        );

        return response()->json(['lu' => true]);
    }

    /** POST /api/v1/publications/tout-lu (auth client) — toutes les publiées. */
    public function toutLu(Request $request): JsonResponse
    {
        $clientId = $this->clientId($request);
        if ($clientId === null) {
            return response()->json(['error' => 'Client introuvable'], 404);
        }

        $ids = Publication::publie()->pluck('id');
        foreach ($ids as $pid) {
            PublicationLecture::firstOrCreate(
                ['publication_id' => (int) $pid, 'client_id' => $clientId],
                ['lu_le' => now()]
            );
        }

        return response()->json(['lu' => true, 'nombre' => $ids->count()]);
    }
}
