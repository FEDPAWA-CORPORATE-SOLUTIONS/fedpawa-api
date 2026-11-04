<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesClientId;
use App\Http\Controllers\Controller;
use App\Models\Publication;
use App\Models\PublicationMedia;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Endpoints ADMIN (auth:sanctum + CheckAdminRole) — CONTRAT-PUBLICATIONS.md §3.
 * Seul un administrateur a accès en écriture (Q1.1-A) ; les clients n'ont
 * AUCUN accès en écriture (403 via la garde).
 *
 * - GET    /api/v1/admin/publications?statut=&page=&par_page=
 * - POST   /api/v1/admin/publications                      (statut brouillon par défaut)
 * - PUT    /api/v1/admin/publications/{id}                 (titre, chapeau, contenu, type, publie_le)
 * - POST   /api/v1/admin/publications/{id}/medias          (fichier + type + ordre ; galerie = appels successifs)
 * - DELETE /api/v1/admin/publications/{id}/medias/{mediaId}
 * - POST   /api/v1/admin/publications/{id}/publier | /depublier
 * - DELETE /api/v1/admin/publications/{id}
 *
 * Limites médias (§2, hypothèses révisables) : images jpg/jpeg/png/webp ≤ 5 Mo ;
 * vidéos mp4/webm ≤ 200 Mo (fichier téléversé, Q1.3-A).
 */
class AdminPublicationController extends Controller
{
    use ResolvesClientId;

    /** 5 Mo en Ko (règle Laravel « max » en kilobytes). */
    private const MAX_IMAGE_KO = 5120;

    /** 200 Mo en Ko. */
    private const MAX_VIDEO_KO = 204800;

    /** GET /api/v1/admin/publications?statut=&page= — tous les statuts. */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'statut' => ['nullable', 'string', Rule::in(Publication::STATUTS)],
        ]);

        $query = Publication::with('medias')->orderByDesc('created_at')->orderByDesc('id');
        if ($statut = $request->query('statut')) {
            $query->where('statut', $statut);
        }
        $parPage = (int) $request->query('par_page', 20);
        if ($parPage < 1 || $parPage > 50) {
            $parPage = 20;
        }
        $paginator = $query->paginate($parPage, ['*'], 'page', max(1, (int) $request->query('page', 1)));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (Publication $p) => $this->resource($p))->all(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'par_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'derniere_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /** POST /api/v1/admin/publications — création, statut « brouillon » par défaut. */
    public function store(Request $request): JsonResponse
    {
        $statut = (string) $request->input('statut', 'brouillon');
        $validated = $request->validate([
            'titre' => ['required', 'string', 'max:200'],
            'chapeau' => ['nullable', 'string', 'max:300'],
            'contenu' => ['required', 'string'],
            'type' => ['nullable', Rule::in(Publication::TYPES)],
            'statut' => ['nullable', Rule::in(Publication::STATUTS)],
            // programme => publie_le requis et futur (Q1.5-A)
            'publie_le' => $statut === 'programme' ? ['required', 'date', 'after:now'] : ['nullable', 'date'],
        ]);

        $pub = new Publication;
        $pub->titre = $validated['titre'];
        $pub->chapeau = $validated['chapeau'] ?? null;
        $pub->contenu = $validated['contenu'];
        $pub->type = $validated['type'] ?? 'texte';
        $pub->statut = $statut;
        $pub->publie_le = match ($statut) {
            'programme' => Carbon::parse($validated['publie_le']),
            'publie' => isset($validated['publie_le']) ? Carbon::parse($validated['publie_le']) : now(),
            default => null,
        };
        $pub->auteur_id = (int) $this->clientId($request);
        $pub->save();

        return response()->json(['publication' => $this->resource($pub->load('medias'))], 201);
    }

    /** PUT /api/v1/admin/publications/{id} — édition (titre, chapeau, contenu, type, publie_le/statut). */
    public function update(Request $request, int $id): JsonResponse
    {
        $pub = Publication::with('medias')->find($id);
        if (! $pub) {
            return response()->json(['error' => 'Publication introuvable'], 404);
        }

        $statutInput = $request->input('statut');
        $hasPublieLe = $request->filled('publie_le');
        // programme, ou simple programmation (publie_le futur sans statut) => date future requise
        $mustBeFuture = $statutInput === 'programme' || ($statutInput === null && $hasPublieLe);

        $validated = $request->validate([
            'titre' => ['sometimes', 'required', 'string', 'max:200'],
            'chapeau' => ['nullable', 'string', 'max:300'],
            'contenu' => ['sometimes', 'required', 'string'],
            'type' => ['nullable', Rule::in(Publication::TYPES)],
            'statut' => ['nullable', Rule::in(Publication::STATUTS)],
            'publie_le' => $mustBeFuture ? ['required', 'date', 'after:now'] : ['nullable', 'date'],
        ]);

        if (array_key_exists('titre', $validated)) {
            $pub->titre = $validated['titre'];
        }
        if (array_key_exists('chapeau', $validated)) {
            $pub->chapeau = $validated['chapeau'];
        }
        if (array_key_exists('contenu', $validated)) {
            $pub->contenu = $validated['contenu'];
        }
        if (array_key_exists('type', $validated) && $validated['type'] !== null) {
            $pub->type = $validated['type'];
        }

        // Programmer = brouillon + publie_le futur (Q1.5-A)
        $statut = $statutInput ?? ($hasPublieLe ? 'programme' : $pub->statut);
        $pub->statut = $statut;
        if ($statut === 'programme') {
            $pub->publie_le = Carbon::parse($validated['publie_le']);
        } elseif ($statut === 'publie') {
            $pub->publie_le = $hasPublieLe ? Carbon::parse($validated['publie_le']) : ($pub->publie_le ?? now());
        } elseif ($request->has('publie_le')) {
            $pub->publie_le = $hasPublieLe ? Carbon::parse($validated['publie_le']) : null;
        }
        $pub->save();

        return response()->json(['publication' => $this->resource($pub->load('medias'))]);
    }

    /** POST /api/v1/admin/publications/{id}/medias — upload fichier + type + ordre. */
    public function ajouterMedia(Request $request, int $id): JsonResponse
    {
        $pub = Publication::find($id);
        if (! $pub) {
            return response()->json(['error' => 'Publication introuvable'], 404);
        }

        $type = (string) $request->input('type', '');
        $fichierRules = ['required', 'file'];
        if ($type === 'image') {
            $fichierRules = ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_IMAGE_KO];
        } elseif ($type === 'video') {
            $fichierRules = ['required', 'file', 'mimes:mp4,webm', 'max:'.self::MAX_VIDEO_KO];
        }

        $validated = $request->validate([
            'type' => ['required', Rule::in(['image', 'video'])],
            'ordre' => ['nullable', 'integer', 'min:0'],
            'fichier' => $fichierRules,
        ]);

        $file = $request->file('fichier');
        $ordre = (int) ($validated['ordre'] ?? ((int) $pub->medias()->max('ordre') + 1));
        $chemin = $file->store("publications/{$pub->id}", 'public');

        $media = $pub->medias()->create([
            'type' => $validated['type'],
            'chemin' => $chemin,
            'nom_origine' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'taille_octets' => (int) ($file->getSize() ?: 0),
            'ordre' => $ordre,
        ]);

        return response()->json(['media' => $this->mediaResource($media)], 201);
    }

    /** DELETE /api/v1/admin/publications/{id}/medias/{mediaId} — supprime fichier + ligne. */
    public function supprimerMedia(int $id, int $mediaId): SymfonyResponse
    {
        $media = PublicationMedia::where('publication_id', $id)->where('id', $mediaId)->first();
        if (! $media) {
            return response()->json(['error' => 'Média introuvable'], 404);
        }

        Storage::disk('public')->delete($media->chemin);
        $media->delete();

        return response()->noContent();
    }

    /** POST /api/v1/admin/publications/{id}/publier — « publier maintenant » (Q1.5-A). */
    public function publier(int $id): JsonResponse
    {
        $pub = Publication::with('medias')->find($id);
        if (! $pub) {
            return response()->json(['error' => 'Publication introuvable'], 404);
        }

        $pub->statut = 'publie';
        if (! $pub->publie_le || $pub->publie_le->isFuture()) {
            $pub->publie_le = now();
        }
        $pub->save();

        return response()->json(['publication' => $this->resource($pub)]);
    }

    /** POST /api/v1/admin/publications/{id}/depublier — retire du public (Q1.4-A). */
    public function depublier(int $id): JsonResponse
    {
        $pub = Publication::with('medias')->find($id);
        if (! $pub) {
            return response()->json(['error' => 'Publication introuvable'], 404);
        }

        $pub->statut = 'depublie';
        $pub->save();

        return response()->json(['publication' => $this->resource($pub)]);
    }

    /** DELETE /api/v1/admin/publications/{id} — suppression (fichiers médias inclus). */
    public function destroy(int $id): SymfonyResponse
    {
        $pub = Publication::with('medias')->find($id);
        if (! $pub) {
            return response()->json(['error' => 'Publication introuvable'], 404);
        }

        foreach ($pub->medias as $media) {
            Storage::disk('public')->delete($media->chemin);
        }
        $pub->medias()->delete();
        $pub->lectures()->delete();
        $pub->delete();

        return response()->noContent();
    }

    // ------------------------------------------------------------------

    private function resource(Publication $p): array
    {
        return [
            'id' => $p->id,
            'titre' => $p->titre,
            'chapeau' => $p->chapeau,
            'contenu' => $p->contenu,
            'type' => $p->type,
            'statut' => $p->statut,
            'publie_le' => $p->publie_le?->toIso8601String(),
            'auteur_id' => (int) $p->auteur_id,
            'vues' => (int) $p->vues,
            'medias' => $p->medias->map(fn (PublicationMedia $m) => $this->mediaResource($m))->values()->all(),
            'created_at' => $p->created_at?->toIso8601String(),
            'updated_at' => $p->updated_at?->toIso8601String(),
        ];
    }

    private function mediaResource(PublicationMedia $m): array
    {
        return [
            'id' => $m->id,
            'type' => $m->type,
            'url' => $m->url(),
            'chemin' => $m->chemin,
            'nom_origine' => $m->nom_origine,
            'mime' => $m->mime,
            'taille_octets' => (int) $m->taille_octets,
            'ordre' => (int) $m->ordre,
        ];
    }
}
