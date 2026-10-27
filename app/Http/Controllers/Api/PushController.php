<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PushSubscribeRequest;
use App\Services\PushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Push PWA :
 * - POST /api/v1/push/subscribe   : upsert souscription (clé : endpoint unique) ;
 * - POST /api/v1/push/unsubscribe  : suppression par endpoint ;
 * - GET  /api/v1/push/vapid        : clé publique VAPID (PUBLIC, sans auth) ;
 * - GET  /api/v1/notifications     : historique du client (20/page, ?unread=1) ;
 * - POST /api/v1/notifications/read : marquer lus {ids:[...]} ou {all:true}.
 */
class PushController extends Controller
{
    public function __construct(private PushService $push) {}

    /** POST /api/v1/push/subscribe (auth:sanctum) */
    public function subscribe(PushSubscribeRequest $request): JsonResponse
    {
        $clientId = $this->clientId($request);
        if ($clientId === null) {
            return response()->json(['error' => 'Client introuvable'], 404);
        }

        $this->push->upsertSubscription(
            $clientId,
            (string) $request->validated('endpoint'),
            (string) $request->validated('keys.p256dh'),
            (string) $request->validated('keys.auth')
        );

        return response()->json(['message' => 'Souscription enregistrée']);
    }

    /** POST /api/v1/push/unsubscribe (auth:sanctum) */
    public function unsubscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048'],
        ]);

        $this->push->deleteSubscription((string) $validated['endpoint']);

        return response()->json(['message' => 'Souscription supprimée']);
    }

    /** GET /api/v1/push/vapid — PUBLIC (sans auth) : clé publique uniquement. */
    public function vapid(): JsonResponse
    {
        $publicKey = (string) env('VAPID_PUBLIC_KEY', '');

        return response()->json(['publicKey' => $publicKey]);
    }

    /** GET /api/v1/notifications (auth:sanctum) — 20/page, ?unread=1, ?page=N. */
    public function notifications(Request $request): JsonResponse
    {
        $clientId = $this->clientId($request);
        if ($clientId === null) {
            return response()->json(['error' => 'Client introuvable'], 404);
        }

        $query = DB::table('notification_events')
            ->where('client_id', $clientId)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        $page = $query->paginate(20)->withQueryString();

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'unread_count' => DB::table('notification_events')
                    ->where('client_id', $clientId)
                    ->whereNull('read_at')
                    ->count(),
            ],
        ]);
    }

    /** POST /api/v1/notifications/read (auth:sanctum) — {ids:[...]} ou {all:true}. */
    public function read(Request $request): JsonResponse
    {
        $clientId = $this->clientId($request);
        if ($clientId === null) {
            return response()->json(['error' => 'Client introuvable'], 404);
        }

        $validated = $request->validate([
            'ids' => ['array'],
            'ids.*' => ['integer', 'min:1'],
            'all' => ['boolean'],
        ]);

        $query = DB::table('notification_events')
            ->where('client_id', $clientId)
            ->whereNull('read_at');

        if (! ($validated['all'] ?? false)) {
            $ids = array_map('intval', $validated['ids'] ?? []);
            if ($ids === []) {
                return response()->json(['error' => 'ids ou all requis'], 400);
            }
            $query->whereIn('id', $ids);
        }

        $count = $query->update(['read_at' => now()->format('Y-m-d H:i:s')]);

        return response()->json(['message' => 'Notifications marquées lues', 'marked' => $count]);
    }

    /** Client (table clients) lié au token Sanctum — convention du repo. */
    private function clientId(Request $request): ?int
    {
        $user = $request->user();
        if (! $user) {
            return null;
        }
        $client = DB::table('clients')->where('email', $user->email)->first();

        return $client ? (int) $client->id : null;
    }
}
