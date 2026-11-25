<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * API admin pour les catégories d'utilisateurs (filtrage des mails).
 * Routes sous /api/v1/admin/mail-categories, garde auth:sanctum + CheckAdminRole.
 *
 * - GET  /api/v1/admin/mail-categories → liste toutes les catégories
 * - POST /api/v1/admin/mail-categories → créer une nouvelle catégorie
 * - DELETE /api/v1/admin/mail-categories/{id} → supprimer une catégorie (si non système)
 */
class UserCategoryController extends Controller
{
    /** GET /api/v1/admin/mail-categories — liste complète. */
    public function index(): JsonResponse
    {
        $categories = UserCategory::orderBy('est_systeme', 'desc')
            ->orderBy('nom')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'nom' => $c->nom,
                'couleur' => $c->couleur,
                'est_systeme' => (bool) $c->est_systeme,
            ]);

        return response()->json(['data' => $categories->values()->all()]);
    }

    /** POST /api/v1/admin/mail-categories — créer une nouvelle catégorie. */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'nom' => ['required', 'string', 'max:100', 'unique:user_categories,nom'],
            'couleur' => ['nullable', 'string', 'size:7', 'regex:/^#([A-Fa-f0-9]{6})$/'],
        ]);

        $categorie = UserCategory::create([
            'nom' => trim($request->string('nom')),
            'couleur' => $request->string('couleur') ?? '#1a73e8',
            'est_systeme' => false,
        ]);

        Log::info('USER_CATEGORY_CREATED', ['id' => $categorie->id, 'nom' => $categorie->nom]);

        return response()->json([
            'id' => $categorie->id,
            'nom' => $categorie->nom,
            'couleur' => $categorie->couleur,
            'est_systeme' => false,
        ], 201);
    }

    /** DELETE /api/v1/admin/mail-categories/{id} — supprimer une catégorie non système. */
    public function destroy(int $id): JsonResponse
    {
        $categorie = UserCategory::find($id);
        if (! $categorie) {
            return response()->json(['error' => 'Catégorie introuvable'], 404);
        }

        if ($categorie->est_systeme) {
            return response()->json(['error' => 'Cette catégorie ne peut pas être supprimée'], 403);
        }

        $categorie->delete();

        Log::info('USER_CATEGORY_DELETED', ['id' => $id]);

        return response()->json(['message' => 'Catégorie supprimée']);
    }
}
