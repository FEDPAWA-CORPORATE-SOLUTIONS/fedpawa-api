<?php

namespace App\Services;

use App\Models\NotificationPreference;
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
 *
 * notifyEvent() : point d'entrée du catalogue d'événements (CONTRAT.md) —
 * écrit TOUJOURS l'événement in-app, n'envoie le push QUE si la préférence
 * (global ET type) du client est active, et ne jette JAMAIS d'exception
 * (un échec push ne casse jamais la requête métier).
 */
class PushService
{
    /** TTL 24 h (86400 s) : notification non délivrée expirée côté push service. */
    private const TTL = 86400;

    /** Catalogue des types d'événements (source de vérité = CONTRAT.md). */
    public const EVENT_TYPES = [
        'contrat_scelle',
        'contrat_signe',
        'dossier_statut',
        'facture_emise',
        'paiement_recu',
        'ticket_reponse',
        'courrier_recu',
        'compte_securite',
    ];

    /** Libellés français exposés par GET/PUT /notifications/preferences. */
    public const EVENT_LABELS = [
        'contrat_scelle' => 'Contrat scellé',
        'contrat_signe' => 'Contrat signé',
        'dossier_statut' => 'Statut du dossier',
        'facture_emise' => 'Facture émise',
        'paiement_recu' => 'Paiement reçu',
        'ticket_reponse' => 'Réponse à un ticket de support',
        'courrier_recu' => 'Courrier reçu',
        'compte_securite' => 'Sécurité du compte (connexion, mot de passe)',
    ];

    /** Types valides (colonne notification_events.type) : legacy + catalogue. */
    public const TYPES = ['facture', 'courrier', 'kyc', 'ticket', 'abonnement', 'systeme', 'contrat_scelle', 'contrat_signe', 'dossier_statut', 'facture_emise', 'paiement_recu', 'ticket_reponse', 'courrier_recu', 'compte_securite'];

    /**
     * Notifie un client pour un type du catalogue :
     * 1. écrit l'événement dans notification_events (toujours) ;
     * 2. envoie le push {title, body, url, type} aux souscriptions du client
     *    UNIQUEMENT si préférences global + type actives.
     *
     * Ne lève jamais d'exception. Retourne un tableau de résultat pour preuve :
     * [type, event_id, push_envoye, motif, abonnements, envois_ok].
     *
     * @return array<string, mixed>
     */
    public function notifyEvent(int $clientId, string $type, string $title, string $body, ?string $url = null): array
    {
        $result = [
            'type' => $type,
            'event_id' => null,
            'push_envoye' => false,
            'motif' => null,
            'abonnements' => 0,
            'envois_ok' => 0,
        ];

        if (! in_array($type, self::EVENT_TYPES, true)) {
            Log::warning("PushService::notifyEvent — type hors catalogue ignoré : {$type}");
            $result['motif'] = 'type_inconnu';

            return $result;
        }

        // Gating e-mail vérifié (CONTRAT-VERIF.md) : un client non vérifié ne
        // reçoit AUCUNE notification (ni push ni événement in-app).
        $emailVerifie = DB::table('clients')->where('id', $clientId)->value('email_verified_at');
        if ($emailVerifie === null) {
            $result['motif'] = 'email_non_verifie';
            Log::info("PushService::notifyEvent — notification {$type} SAUTÉE (e-mail non vérifié) client {$clientId}");

            return $result;
        }

        try {
            // 1. Historique in-app (toujours, même si préférence OFF)
            $result['event_id'] = DB::table('notification_events')->insertGetId([
                'client_id' => $clientId,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'url' => $url,
                'created_at' => now()->format('Y-m-d H:i:s'),
            ]);

            // 2. Préférences : global ET type doivent être actifs
            if (! NotificationPreference::enabledFor($clientId, $type)) {
                $result['motif'] = 'preference_desactivee';
                Log::info("PushService: push {$type} NON envoyé (préférence désactivée) client {$clientId} — événement #{$result['event_id']} conservé");

                return $result;
            }

            // 3. Envoi WebPush
            $subs = DB::table('push_subscriptions')->where('client_id', $clientId)->get();
            $result['abonnements'] = $subs->count();
            if ($subs->isEmpty()) {
                $result['motif'] = 'aucune_souscription';

                return $result;
            }
            if (! $this->vapidConfigured()) {
                $result['motif'] = 'vapid_non_configure';

                return $result;
            }

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
                    $result['envois_ok']++;
                    continue;
                }
                if ($report->isSubscriptionExpired()) {
                    $this->purgeEndpoint($report->getEndpoint());
                } else {
                    Log::warning('WebPush échec (non purge) : '.$report->getEndpoint().' — '.$report->getReason());
                }
            }
            $result['push_envoye'] = $result['envois_ok'] > 0;
            if (! $result['push_envoye']) {
                $result['motif'] = 'echec_push_non_fatal';
                Log::warning("PushService: aucun envoi réussi pour {$type} client {$clientId} (endpoints injoignables ?) — requête métier non impactée");
            }
        } catch (\Throwable $e) {
            // Push = best-effort : JAMAIS d'exception remontée à l'appelant
            $result['motif'] = 'erreur_interne_non_fatale';
            Log::warning('PushService::notifyEvent — échec interne non fatal — '.$e->getMessage());
        }

        return $result;
    }

    public function sendToClient(int $clientId, string $type, string $title, string $body, ?string $url = null): void
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Type de notification invalide : {$type}");
        }

        // Gating e-mail vérifié (CONTRAT-VERIF.md) : client non vérifié = saut total.
        $emailVerifie = DB::table('clients')->where('id', $clientId)->value('email_verified_at');
        if ($emailVerifie === null) {
            Log::info("PushService::sendToClient — notification {$type} SAUTÉE (e-mail non vérifié) client {$clientId}");

            return;
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

    /**
     * Upsert d'une souscription (clé naturelle : endpoint unique).
     *
     * Anti-IDOR : l'endpoint est unique globalement, mais il n'appartient
     * qu'à UN client. On refuse d'écraser la ligne d'un autre compte avec
     * des clés différentes (divertissement / coupure des notifications d'un
     * tiers). Seul le même navigateur — même endpoint ET mêmes clés de
     * souscription, donc même service worker — peut changer de propriétaire
     * (cas légitime : deux comptes sur le même poste).
     *
     * @return bool true = enregistrée ; false = endpoint détenu par un autre compte
     */
    public function upsertSubscription(int $clientId, string $endpoint, string $p256dh, string $auth): bool
    {
        $existing = DB::table('push_subscriptions')->where('endpoint', $endpoint)->first();

        if ($existing && (int) $existing->client_id !== $clientId) {
            $sameBrowser = hash_equals((string) $existing->p256dh, $p256dh)
                && hash_equals((string) $existing->auth, $auth);

            if (! $sameBrowser) {
                Log::warning("PushService: souscription d'un autre client refusée (client {$clientId})");

                return false;
            }
        }

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

        return true;
    }

    /**
     * Suppression D'UNE souscription du client courant (anti-IDOR : le
     * client_id est dans le WHERE, un compte ne peut pas supprimer la
     * souscription d'un autre).
     */
    public function deleteSubscription(int $clientId, string $endpoint): int
    {
        return DB::table('push_subscriptions')
            ->where('client_id', $clientId)
            ->where('endpoint', $endpoint)
            ->delete();
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
