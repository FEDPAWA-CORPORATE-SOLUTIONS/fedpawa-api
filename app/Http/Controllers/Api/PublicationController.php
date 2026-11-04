<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Publication;
use App\Models\PublicationMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints PUBLICS (sans auth) — CONTRAT-PUBLICATIONS.md §3 :
 * - GET /api/v1/publications?page=&par_page= : liste paginée des publiées
 *   (statut=publie ET publie_le<=now), plus récentes d'abord ;
 * - GET /api/v1/publications/{id} : détail + médias ordonnés, 404 si non publiée,
 *   incrémente le compteur de vues.
 */
class PublicationController extends Controller
{
    /** GET /api/v1/publications — PUBLIC. */
    public function index(Request $request): JsonResponse
    {
        $parPage = (int) $request->query('par_page', 10);
        if ($parPage < 1 || $parPage > 50) {
            $parPage = 10;
        }
        $page = max(1, (int) $request->query('page', 1));

        $paginator = Publication::publie()
            ->orderByDesc('publie_le')
            ->orderByDesc('id')
            ->paginate($parPage, ['*'], 'page', $page);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (Publication $p) => [
                'id' => $p->id,
                'titre' => $p->titre,
                'chapeau' => $p->chapeau,
                'type' => $p->type,
                'publie_le' => $p->publie_le?->toIso8601String(),
                'medias' => $p->medias->map(fn (PublicationMedia $m) => [
                    'type' => $m->type,
                    'url' => $m->url(),
                    'ordre' => $m->ordre,
                ])->values()->all(),
            ])->all(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'par_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'derniere_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /** GET /api/v1/publications/{id} — PUBLIC, 404 si inexistante ou non publiée. */
    public function show(int $id): JsonResponse
    {
        $publication = Publication::with('medias')->find($id);
        if (! $publication || ! $publication->estPubliee()) {
            return response()->json(['error' => 'Publication introuvable'], 404);
        }

        $publication->increment('vues');

        return response()->json([
            'id' => $publication->id,
            'titre' => $publication->titre,
            'chapeau' => $publication->chapeau,
            'contenu' => $publication->contenu,
            'type' => $publication->type,
            'publie_le' => $publication->publie_le?->toIso8601String(),
            'vues' => (int) $publication->vues,
            'medias' => $publication->medias->map(fn (PublicationMedia $m) => [
                'id' => $m->id,
                'type' => $m->type,
                'url' => $m->url(),
                'ordre' => $m->ordre,
            ])->values()->all(),
        ]);
    }
}
