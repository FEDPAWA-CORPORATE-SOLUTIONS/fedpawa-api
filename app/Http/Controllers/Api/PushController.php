<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PushSubscribeRequest;
use App\Models\NotificationPreference;
use App\Services\PushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Push PWA :
 * - POST /api/v1/push/subscribe   : upsert souscription (clé : endpoint unique) ;
 * - POST /api/v1/push/unsubscribe  : suppression par endpoint ;
 * - GET  /api/v1/push/vapid        : clé publique VAPID (PUBLIC, sans auth) ;
 * - GET  /api/v1/notifications     : historique du client (20/page, ?unread=1) ;
 * - POST /api/v1/notifications/read : marquer lus {ids:[...]} ou {all:true} ;
 * - GET  /api/v1/notifications/preferences : préférences (global + catalogue) ;
 * - PUT  /api/v1/notifications/preferences : mise à jour partielle (422 si type inconnu).
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

        $ok = $this->push->upsertSubscription(
            $clientId,
            (string) $request->validated('endpoint'),
            (string) $request->validated('keys.p256dh'),
            (string) $request->validated('keys.auth')
        );

        if (! $ok) {
            return response()->json([
                'error' => 'Cette souscription appartient à un autre compte',
            ], 409);
        }

        return response()->json(['message' => 'Souscription enregistrée']);
    }

    /** POST /api/v1/push/unsubscribe (auth:sanctum) */
    public function unsubscribe(Request $request): JsonResponse
    {
        $clientId = $this->clientId($request);
        if ($clientId === null) {
            return response()->json(['error' => 'Client introuvable'], 404);
        }

        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048'],
        ]);

        // Suppression scopée : un compte ne peut retirer que SES souscriptions.
        $this->push->deleteSubscription($clientId, (string) $validated['endpoint']);

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

        $validator = Validator::make($request->all(), [
            'ids' => ['array'],
            'ids.*' => ['integer', 'min:1'],
            // Le front marque lues les notifications d'un onglet (?tab=) par
            // type : sans 'types', ce marquage répondait 400 et ne faisait rien.
            'types' => ['array'],
            'types.*' => ['string', Rule::in(PushService::TYPES)],
            'all' => ['boolean'],
        ]);

        // Convention du repo : validation invalide -> 400 {error} (jamais 422).
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 400);
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();

        $query = DB::table('notification_events')
            ->where('client_id', $clientId)
            ->whereNull('read_at');

        if (! ($validated['all'] ?? false)) {
            $ids = array_map('intval', $validated['ids'] ?? []);
            $types = array_values(array_unique(array_map('strval', $validated['types'] ?? [])));

            if ($ids !== []) {
                $query->whereIn('id', $ids);
            } elseif ($types !== []) {
                // Toujours scopé au client : un type ne franchit pas les comptes.
                $query->whereIn('type', $types);
            } else {
                return response()->json(['error' => 'ids, types ou all requis'], 400);
            }
        }

        $count = $query->update(['read_at' => now()->format('Y-m-d H:i:s')]);

        return response()->json(['message' => 'Notifications marquées lues', 'marked' => $count]);
    }

    /**
     * GET /api/v1/notifications/preferences (auth:sanctum)
     * → {global: bool, events: [{type, label, enabled}]} — défaut : tout activé.
     */
    public function preferences(Request $request): JsonResponse
    {
        $clientId = $this->clientId($request);
        if ($clientId === null) {
            return response()->json(['error' => 'Client introuvable'], 404);
        }

        return response()->json($this->preferencesPayload($clientId));
    }

    /**
     * PUT /api/v1/notifications/preferences (auth:sanctum)
     * Body {global?: bool, events?: {<type>: bool}} — partiel accepté.
     * 422 si un type d'événement est inconnu du catalogue. → 200 JSON complet.
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $clientId = $this->clientId($request);
        if ($clientId === null) {
            return response()->json(['error' => 'Client introuvable'], 404);
        }

        $validator = Validator::make($request->all(), [
            'global' => ['sometimes', 'boolean'],
            'events' => ['sometimes', 'array'],
            'events.*' => ['boolean'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $events = $request->input('events');
        if (is_array($events)) {
            foreach ($events as $type => $enabled) {
                if (! in_array((string) $type, PushService::EVENT_TYPES, true)) {
                    return response()->json([
                        'error' => "Type d'événement inconnu : {$type}",
                        'types_valides' => PushService::EVENT_TYPES,
                    ], 422);
                }
            }
        }

        if ($request->has('global')) {
            NotificationPreference::updateOrCreate(
                ['user_id' => $clientId, 'type' => NotificationPreference::GLOBAL],
                ['enabled' => (bool) $request->input('global')]
            );
        }

        if (is_array($events)) {
            foreach ($events as $type => $enabled) {
                NotificationPreference::updateOrCreate(
                    ['user_id' => $clientId, 'type' => (string) $type],
                    ['enabled' => (bool) $enabled]
                );
            }
        }

        return response()->json($this->preferencesPayload($clientId));
    }

    /** Forme exacte du contrat : {global, events:[{type,label,enabled}]}. */
    private function preferencesPayload(int $clientId): array
    {
        $rows = NotificationPreference::where('user_id', $clientId)->pluck('enabled', 'type');

        $events = [];
        foreach (PushService::EVENT_TYPES as $type) {
            $events[] = [
                'type' => $type,
                'label' => PushService::EVENT_LABELS[$type],
                'enabled' => isset($rows[$type]) ? (bool) $rows[$type] : true,
            ];
        }

        return [
            'global' => isset($rows[NotificationPreference::GLOBAL]) ? (bool) $rows[NotificationPreference::GLOBAL] : true,
            'events' => $events,
        ];
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
