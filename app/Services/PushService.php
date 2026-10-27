<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Push backend PWA (RFC 8030 / Web Push) :
 * - notification_events : historique in-app (GET /notifications) ;
 * - WebPush vers toutes les souscriptions actives du client (TTL 24 h, batch),
 *   purge des endpoints morts (404/410) après envoi.
 */
class PushService
{
    /** TTL 24 h (86400 s) : notification non délivrée expirée côté push service. */
    private const TTL = 86400;

    /** Types valides (colonne notification_events.type). */
    private const TYPES = ['facture', 'courrier', 'kyc', 'ticket', 'abonnement', 'systeme'];

    public function sendToClient(int $clientId, string $type, string $title, string $body, ?string $url = null): void
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Type de notification invalide : {$type}");
        }

        // 1. Historique in-app (toujours, même sans souscription push)
        DB::table('notification_events')->insert([
            'client_id' => $clientId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'created_at' => now()->format('Y-m-d H:i:s'),
        ]);

        // 2. Envoi WebPush aux souscriptions du client
        $subs = DB::table('push_subscriptions')->where('client_id', $clientId)->get();
        if ($subs->isEmpty() || ! $this->vapidConfigured()) {
            return;
        }

        try {
            $webPush = app(WebPush::class);

            foreach ($subs as $sub) {
                $webPush->queueNotification(
                    new Subscription($sub->endpoint, $sub->p256dh, $sub->auth),
                    json_encode([
                        'title' => $title,
                        'body' => $body,
                        'url' => $url,
                        'type' => $type,
                    ], JSON_UNESCAPED_UNICODE)
                );
            }

            /** @var MessageSentReport $report */
            foreach ($webPush->flush() as $report) {
                if ($report->isSuccess()) {
                    continue;
                }
                // 404/410 : souscription morte côté push service -> purge
                if ($report->isSubscriptionExpired()) {
                    $this->purgeEndpoint($report->getEndpoint());
                } else {
                    Log::warning('WebPush échec (non purge) : '.$report->getEndpoint().' — '.$report->getReason());
                }
            }
        } catch (\Throwable $e) {
            // Push = best-effort : ne casse jamais le flux métier
            Log::warning('PushService: échec envoi WebPush — '.$e->getMessage());
        }
    }

    /** Upsert d'une souscription (clé naturelle : endpoint unique). */
    public function upsertSubscription(int $clientId, string $endpoint, string $p256dh, string $auth): void
    {
        DB::table('push_subscriptions')->updateOrInsert(
            ['endpoint' => $endpoint],
            [
                'client_id' => $clientId,
                'p256dh' => $p256dh,
                'auth' => $auth,
                'updated_at' => now()->format('Y-m-d H:i:s'),
                'created_at' => now()->format('Y-m-d H:i:s'),
            ]
        );
    }

    public function deleteSubscription(string $endpoint): int
    {
        return DB::table('push_subscriptions')->where('endpoint', $endpoint)->delete();
    }

    public function purgeEndpoint(string $endpoint): void
    {
        $deleted = DB::table('push_subscriptions')->where('endpoint', $endpoint)->delete();
        if ($deleted > 0) {
            Log::info("PushService: souscription morte purgée ({$endpoint})");
        }
    }

    private function vapidConfigured(): bool
    {
        $public = (string) config('webpush.vapid_public_key', env('VAPID_PUBLIC_KEY', ''));
        $private = (string) config('webpush.vapid_private_key', env('VAPID_PRIVATE_KEY', ''));

        return $public !== '' && $private !== '';
    }
}
