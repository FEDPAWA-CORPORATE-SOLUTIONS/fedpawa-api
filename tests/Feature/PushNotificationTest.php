<?php

namespace Tests\Feature;

use App\Services\PushService;
use Illuminate\Support\Facades\DB;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\BootstrapsBusinessSchema;
use Tests\TestCase;

/**
 * Push PWA : souscriptions, vapid public, notifications filtrées par client,
 * marquage lu, envoi WebPush mocké, purge des endpoints morts (404/410).
 */
class PushNotificationTest extends TestCase
{
    use BootstrapsBusinessSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapBusinessSchema();
        // Tables push (migrations formelles) — mêmes schémas
        if (! \Schema::hasTable('push_subscriptions')) {
            \Artisan::call('migrate', ['--force' => true, '--path' => '/database/migrations']);
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** POST /push/subscribe sans auth -> 401 (toutes sanctum sauf vapid). */
    public function test_subscribe_sans_auth_401(): void
    {
        $this->postJson('/api/v1/push/subscribe', $this->subPayload())
            ->assertStatus(401);
        $this->postJson('/api/v1/push/unsubscribe', ['endpoint' => 'https://x'])
            ->assertStatus(401);
        $this->getJson('/api/v1/notifications')->assertStatus(401);
        $this->postJson('/api/v1/notifications/read', ['all' => true])->assertStatus(401);
    }

    /** GET /push/vapid PUBLIC — sans auth, renvoie la clé publique. */
    public function test_vapid_public_sans_auth(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        // env() vide en tests : la route expose la clé telle quelle (chaîne vide
        // si non configurée) — PUBLIC sans auth, statut 200 garanti.
        $res = $this->getJson('/api/v1/push/vapid');
        $res->assertStatus(200)->assertJsonStructure(['publicKey']);
        $this->assertIsString($res->json('publicKey'));
    }

    /** Subscribe : upsert par endpoint — re-souscrire met à jour, pas de doublon. */
    public function test_subscribe_upsert_par_endpoint(): void
    {
        ['user' => $user, 'id' => $clientId] = $this->businessClient('push-a@example.com');
        $payload = $this->subPayload();
        $endpoint = $payload['endpoint'];

        $this->actingAs($user)->postJson('/api/v1/push/subscribe', $payload)
            ->assertStatus(200)->assertJson(['message' => 'Souscription enregistrée']);

        $this->assertDatabaseHas('push_subscriptions', [
            'client_id' => $clientId,
            'p256dh' => $payload['keys']['p256dh'],
        ]);

        // Même endpoint, nouvelles clés -> update, pas d'insert
        $payload2 = $this->subPayload();
        $payload2['endpoint'] = $endpoint; // même endpoint = upsert
        $payload2['keys'] = ['p256dh' => 'NEWP256DH', 'auth' => 'NEWAUTH'];
        $this->actingAs($user)->postJson('/api/v1/push/subscribe', $payload2)
            ->assertStatus(200);

        $this->assertSame(1, DB::table('push_subscriptions')->where('client_id', $clientId)->count());
        $this->assertDatabaseHas('push_subscriptions', ['p256dh' => 'NEWP256DH']);
        $this->assertDatabaseMissing('push_subscriptions', ['p256dh' => $payload['keys']['p256dh']]);
    }

    /** Subscribe : payload incomplet -> 400 {error} (ApiFormRequest). */
    public function test_subscribe_validation_400(): void
    {
        ['user' => $user] = $this->businessClient('push-b@example.com');
        $this->actingAs($user)->postJson('/api/v1/push/subscribe', ['endpoint' => 'https://fcm.test/ep'])
            ->assertStatus(400)->assertJsonStructure(['error']);
    }

    /** Unsubscribe : supprime la ligne endpoint. */
    public function test_unsubscribe_supprime_ligne(): void
    {
        ['user' => $user, 'id' => $clientId] = $this->businessClient('push-c@example.com');
        $payload = $this->subPayload();

        $this->actingAs($user)->postJson('/api/v1/push/subscribe', $payload)->assertStatus(200);
        $this->actingAs($user)->postJson('/api/v1/push/unsubscribe', ['endpoint' => $payload['endpoint']])
            ->assertStatus(200)->assertJson(['message' => 'Souscription supprimée']);

        $this->assertDatabaseMissing('push_subscriptions', ['client_id' => $clientId]);
    }

    /** GET /notifications : A ne voit pas B ; ?unread=1 ; pagination 20/page. */
    public function test_notifications_filtrees_par_client(): void
    {
        ['user' => $userA, 'id' => $idA] = $this->businessClient('push-d@example.com');
        ['user' => $userB, 'id' => $idB] = $this->businessClient('push-e@example.com');

        $svc = $this->app->make(PushService::class);
        $svc->sendToClient($idA, 'facture', 'F1', 'b1', '/u');
        $svc->sendToClient($idA, 'courrier', 'F2', 'b2', '/u');
        $svc->sendToClient($idB, 'ticket', 'F3', 'b3', '/u');

        // A voit ses 2 events, pas ceux de B
        $res = $this->actingAs($userA)->getJson('/api/v1/notifications');
        $res->assertStatus(200);
        $this->assertSame(2, $res->json('meta.total'));
        $titles = array_column($res->json('data'), 'title');
        sort($titles);
        $this->assertSame(['F1', 'F2'], $titles);
        $this->assertSame(2, $res->json('meta.unread_count'));

        // Marque F1 lue -> unread=1 ne renvoie plus que F2
        $id = DB::table('notification_events')->where('client_id', $idA)->where('title', 'F1')->value('id');
        $this->actingAs($userA)->postJson('/api/v1/notifications/read', ['ids' => [$id]])
            ->assertStatus(200)->assertJson(['marked' => 1]);

        $unread = $this->actingAs($userA)->getJson('/api/v1/notifications?unread=1');
        $this->assertSame(1, $unread->json('meta.total'));
        $this->assertSame('F2', $unread->json('data.0.title'));

        // B voit toujours son event non lu
        $resB = $this->actingAs($userB)->getJson('/api/v1/notifications?unread=1');
        $this->assertSame(1, $resB->json('meta.total'));
    }

    /** Pagination : 25 events -> 2 pages de 20/5. */
    public function test_notifications_pagination_20_par_page(): void
    {
        ['user' => $user, 'id' => $id] = $this->businessClient('push-f@example.com');
        $svc = $this->app->make(PushService::class);
        for ($i = 0; $i < 25; $i++) {
            $svc->sendToClient($id, 'systeme', "E{$i}", 'b', null);
        }

        $p1 = $this->actingAs($user)->getJson('/api/v1/notifications');
        $this->assertSame(20, count($p1->json('data')));
        $this->assertSame(25, $p1->json('meta.total'));
        $this->assertSame(2, $p1->json('meta.last_page'));

        $p2 = $this->actingAs($user)->getJson('/api/v1/notifications?page=2');
        $this->assertSame(5, count($p2->json('data')));
    }

    /** POST /notifications/read {all:true} marque tout lu pour CE client. */
    public function test_read_all_marque_tout_lu_client_courant(): void
    {
        ['user' => $userA, 'id' => $idA] = $this->businessClient('push-g@example.com');
        ['user' => $userB, 'id' => $idB] = $this->businessClient('push-h@example.com');

        $svc = $this->app->make(PushService::class);
        $svc->sendToClient($idA, 'facture', 'A1', 'b', null);
        $svc->sendToClient($idA, 'kyc', 'A2', 'b', null);
        $svc->sendToClient($idB, 'kyc', 'B1', 'b', null);

        $this->actingAs($userA)->postJson('/api/v1/notifications/read', ['all' => true])
            ->assertStatus(200)->assertJson(['marked' => 2]);

        // B reste non lu
        $this->assertSame(1, $this->actingAs($userB)->getJson('/api/v1/notifications?unread=1')->json('meta.total'));
        $this->assertSame(0, $this->actingAs($userA)->getJson('/api/v1/notifications?unread=1')->json('meta.total'));
    }

    /** PushService : envoi WebPush mocké vers toutes les souscriptions du client. */
    public function test_push_service_envoie_webpush_a_toutes_souscriptions(): void
    {
        ['id' => $clientId] = $this->businessClient('push-i@example.com');

        DB::table('push_subscriptions')->insert([
            ['client_id' => $clientId, 'endpoint' => 'https://fcm.test/ep1', 'p256dh' => 'K1', 'auth' => 'A1', 'created_at' => now(), 'updated_at' => now()],
            ['client_id' => $clientId, 'endpoint' => 'https://fcm.test/ep2', 'p256dh' => 'K2', 'auth' => 'A2', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $report = Mockery::mock(MessageSentReport::class);
        $report->shouldReceive('isSuccess')->andReturn(true);

        $webPush = Mockery::mock(WebPush::class);
        $webPush->shouldReceive('queueNotification')
            ->twice()
            ->withArgs(function (Subscription $sub, ?string $payload): bool {
                return str_starts_with($sub->getEndpoint(), 'https://fcm.test/ep')
                    && str_contains((string) $payload, 'Facture prête');
            });
        $webPush->shouldReceive('flush')->andReturnUsing(function () use ($report) {
            yield $report;
        });

        $this->app->instance(WebPush::class, $webPush);
        $this->withVapid();

        $svc = $this->app->make(PushService::class);
        $svc->sendToClient($clientId, 'facture', 'Facture prête', 'Votre facture est disponible', '/f');

        $this->assertDatabaseHas('notification_events', [
            'client_id' => $clientId,
            'type' => 'facture',
            'title' => 'Facture prête',
        ]);
    }

    /** PushService : réponse 404/410 -> purge de la souscription morte. */
    public function test_push_service_purge_endpoint_404(): void
    {
        ['id' => $clientId] = $this->businessClient('push-j@example.com');
        DB::table('push_subscriptions')->insert([
            'client_id' => $clientId, 'endpoint' => 'https://fcm.test/dead', 'p256dh' => 'K', 'auth' => 'A',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $report = Mockery::mock(MessageSentReport::class);
        $report->shouldReceive('isSuccess')->andReturn(false);
        $report->shouldReceive('isSubscriptionExpired')->andReturn(true);
        $report->shouldReceive('getEndpoint')->andReturn('https://fcm.test/dead');

        $webPush = Mockery::mock(WebPush::class);
        $webPush->shouldReceive('queueNotification');
        $webPush->shouldReceive('flush')->andReturnUsing(function () use ($report) {
            yield $report;
        });

        $this->app->instance(WebPush::class, $webPush);
        $this->withVapid();

        $svc = $this->app->make(PushService::class);
        $svc->sendToClient($clientId, 'systeme', 'Test', 'body', null);

        $this->assertDatabaseMissing('push_subscriptions', ['endpoint' => 'https://fcm.test/dead']);
        // L'événement in-app reste créé même si l'envoi échoue
        $this->assertDatabaseHas('notification_events', ['client_id' => $clientId, 'title' => 'Test']);
    }

    /** PushService : sans VAPID configurée (tests), pas d'envoi, event créé quand même. */
    public function test_push_service_sans_vapid_event_quand_meme(): void
    {
        ['id' => $clientId] = $this->businessClient('push-k@example.com');
        DB::table('push_subscriptions')->insert([
            'client_id' => $clientId, 'endpoint' => 'https://fcm.test/ep9', 'p256dh' => 'K', 'auth' => 'A',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $svc = new PushService;
        $svc->sendToClient($clientId, 'abonnement', 'Renouvellement proche', 'b', null);

        $this->assertDatabaseHas('notification_events', ['client_id' => $clientId, 'type' => 'abonnement']);
    }

    /** Type invalide -> exception (contrat de l'API interne). */
    public function test_push_service_type_invalide_rejete(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $svc = new PushService;
        $svc->sendToClient(1, 'invalide', 'T', 'B', null);
    }

    /**
     * ANTI-IDOR : un compte ne peut PAS écraser la souscription d'un autre en
     * rejouant son endpoint avec des clés différentes -> 409, ligne intacte.
     */
    public function test_subscribe_refuse_endpoint_d_un_autre_client(): void
    {
        ['user' => $userA, 'id' => $idA] = $this->businessClient('push-idor-a@example.com');
        ['user' => $userB, 'id' => $idB] = $this->businessClient('push-idor-b@example.com');

        $payloadB = $this->subPayload();
        $this->actingAs($userB)->postJson('/api/v1/push/subscribe', $payloadB)->assertStatus(200);

        $payloadA = $this->subPayload();
        $payloadA['endpoint'] = $payloadB['endpoint']; // même endpoint, clés de A
        $this->actingAs($userA)->postJson('/api/v1/push/subscribe', $payloadA)
            ->assertStatus(409)->assertJsonStructure(['error']);

        $this->assertDatabaseHas('push_subscriptions', [
            'endpoint' => $payloadB['endpoint'],
            'client_id' => $idB,
        ]);
        $this->assertSame(0, DB::table('push_subscriptions')->where('client_id', $idA)->count());
    }

    /** Même navigateur (endpoint ET clés identiques) : transfert légitime. */
    public function test_subscribe_transfere_la_souscription_du_meme_navigateur(): void
    {
        ['user' => $userA] = $this->businessClient('push-sw-a@example.com');
        ['user' => $userB, 'id' => $idB] = $this->businessClient('push-sw-b@example.com');

        $payload = $this->subPayload();
        $this->actingAs($userA)->postJson('/api/v1/push/subscribe', $payload)->assertStatus(200);
        $this->actingAs($userB)->postJson('/api/v1/push/subscribe', $payload)->assertStatus(200);

        $this->assertDatabaseHas('push_subscriptions', [
            'endpoint' => $payload['endpoint'],
            'client_id' => $idB,
        ]);
        $this->assertSame(1, DB::table('push_subscriptions')->count());
    }

    /** ANTI-IDOR : l'unsubscribe d'un autre compte ne supprime rien. */
    public function test_unsubscribe_ne_supprime_pas_la_souscription_d_un_autre(): void
    {
        ['user' => $userA] = $this->businessClient('push-del-a@example.com');
        ['user' => $userB, 'id' => $idB] = $this->businessClient('push-del-b@example.com');

        $payload = $this->subPayload();
        $this->actingAs($userB)->postJson('/api/v1/push/subscribe', $payload)->assertStatus(200);

        $this->actingAs($userA)
            ->postJson('/api/v1/push/unsubscribe', ['endpoint' => $payload['endpoint']])
            ->assertStatus(200);

        $this->assertDatabaseHas('push_subscriptions', [
            'endpoint' => $payload['endpoint'],
            'client_id' => $idB,
        ]);
    }

    /** read {types} : marque lues CES types, pour le client courant uniquement. */
    public function test_read_par_types_marque_uniquement_les_types_demandes(): void
    {
        ['user' => $userA, 'id' => $idA] = $this->businessClient('push-types-a@example.com');
        ['user' => $userB, 'id' => $idB] = $this->businessClient('push-types-b@example.com');

        $svc = $this->app->make(PushService::class);
        $svc->sendToClient($idA, 'courrier', 'C1', 'b', null);
        $svc->sendToClient($idA, 'facture', 'F1', 'b', null);
        $svc->sendToClient($idB, 'courrier', 'C2', 'b', null);

        $this->actingAs($userA)->postJson('/api/v1/notifications/read', ['types' => ['courrier']])
            ->assertStatus(200)->assertJson(['marked' => 1]);

        // A : courrier lu, facture non lue (1 restante) ; B : son courrier intact.
        $this->assertSame(1, $this->actingAs($userA)->getJson('/api/v1/notifications?unread=1')->json('meta.total'));
        $this->assertSame(1, $this->actingAs($userB)->getJson('/api/v1/notifications?unread=1')->json('meta.total'));
    }

    /** read {types} avec un type inconnu -> 400 (convention API, jamais 422). */
    public function test_read_type_invalide_400(): void
    {
        ['user' => $user] = $this->businessClient('push-types-c@example.com');

        $this->actingAs($user)
            ->postJson('/api/v1/notifications/read', ['types' => ['invalide']])
            ->assertStatus(400)->assertJsonStructure(['error']);
    }

    private function subPayload(): array
    {
        return [
            'endpoint' => 'https://fcm.test/send/'.bin2hex(random_bytes(8)),
            'keys' => [
                'p256dh' => 'BPub'.bin2hex(random_bytes(8)),
                'auth' => 'Auth'.bin2hex(random_bytes(8)),
            ],
        ];
    }

    /** Active VAPID en runtime pour que PushService tente l'envoi (mocké). */
    private function withVapid(): void
    {
        config(['webpush.vapid_public_key' => 'BPubTestKey123', 'webpush.vapid_private_key' => 'PrivTestKey456']);
    }
}
