<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CourrierAddRequest;
use App\Services\PushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Parité de src/Controller/CourrierController.php :
 * - GET  /api/v1/courriers        : liste des courriers du client (date_reception DESC) ;
 * - POST /api/v1/admin/courrier/add : admin, insert courriers
 *   (statut 'non_lu', date_reception now) — 403 si non admin.
 */
class CourrierController extends Controller
{
    public function __construct(private PushService $push) {}

    /** GET /api/v1/courriers */
    public function index(Request $request): JsonResponse
    {
        $clientId = $this->clientId($request);
        if ($clientId === null) {
            return response()->json(['error' => 'Non autorisé'], 401);
        }

        try {
            $rows = DB::table('courriers')
                ->where('client_id', $clientId)
                ->orderByDesc('date_reception')
                ->get()
                ->map(fn ($r) => (array) $r)
                ->all();

            return response()->json(['data' => $rows]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Erreur interne'], 500);
        }
    }

    /** POST /api/v1/admin/courrier/add (admin) */
    public function add(CourrierAddRequest $request): JsonResponse
    {
        if (! $this->isAdmin($request)) {
            return response()->json(['error' => 'Non autorisé'], 403);
        }

        try {
            $clientId = (int) $request->validated('client_id');
            DB::table('courriers')->insert([
                'client_id' => $clientId,
                'date_reception' => now()->format('Y-m-d H:i:s'),
                'scan' => $request->validated('scan_path'),
                'statut' => 'non_lu',
            ]);

            // Notification catalogue : courrier_recu (best-effort, jamais fatal)
            $this->push->notifyEvent(
                $clientId,
                'courrier_recu',
                'Nouveau courrier disponible',
                'Un nouveau courrier vous attend dans votre espace client.',
                '/espace-client?tab=courriers'
            );

            return response()->json(['message' => 'Courrier ajouté']);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Erreur interne'], 500);
        }
    }

    private function clientId(Request $request): ?int
    {
        $user = $request->user();
        if (! $user) {
            return null;
        }
        $client = DB::table('clients')->where('email', $user->email)->first();

        return $client ? (int) $client->id : null;
    }

    private function isAdmin(Request $request): bool
    {
        $user = $request->user();
        if (! $user) {
            return false;
        }
        $client = DB::table('clients')->where('email', $user->email)->first();

        return $client && ($client->role ?? 'client') === 'admin';
    }
}
