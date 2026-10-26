<?php

namespace Tests\Feature;

use App\Services\CampayService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BootstrapsBusinessSchema;
use Tests\TestCase;

/**
 * Paiement Campay simulé + webhook signé idempotent :
 * - collect() attaque l'API Campay (mock HTTP) avec le bon contrat ;
 * - webhook : signature HMAC-SHA256 vérifiée, idempotence par event_id
 *   (2x le même event = 1 seul effet), statuts harmonisés, W-10 facture.
 */
class PaymentWebhookTest extends TestCase
{
    use BootstrapsBusinessSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapBusinessSchema();
        Config::set('fedpawa.CAMPAY_ENABLED', '1');
        Config::set('fedpaya', null);
        Config::set('fedpawa.CAMPAY_WEBHOOK_SECRET', 'test-secret');
        Config::set('fedpawa.CAMPAY_MODE', 'demo');
        Config::set('fedpawa.CAMPAY_TOKEN', 'app-key-token');
    }

    private function insertPendingPayment(int $clientId, string $tx, float $montant = 50000): int
    {
        return (int) DB::table('paiements')->insertGetId([
            'client_id' => $clientId, 'montant' => $montant, 'mode_paiement' => 'campay',
            'transaction_id' => $tx, 'date' => now()->format('Y-m-d H:i:s'), 'statut' => 'en_attente',
        ]);
    }

    private function signedHeaders(array $payload): array
    {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);

        return [
            'X-Campay-Signature' => 'sha256='.hash_hmac('sha256', $raw, 'test-secret'),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    public function test_collect_appelle_campay_avec_le_bon_contrat(): void
    {
        Http::fake([
            'demo.campay.net/api/collect/' => Http::response(['reference' => 'CAMPAY-REF-1', 'status' => 'PENDING'], 200),
        ]);

        $svc = new CampayService;
        $res = $svc->collect(25500.0, '237690000000', 'SUB_42');

        $this->assertSame('CAMPAY-REF-1', $res['reference']);
        Http::assertSent(function ($request) {
            $this->assertSame('https://demo.campay.net/api/collect/', $request->url());
            $this->assertSame('25500', $request->data()['amount']); // entier string XAF
            $this->assertSame('XAF', $request->data()['currency']);
            $this->assertSame('237690000000', $request->data()['from']);
            $this->assertSame('SUB_42', $request->data()['external_reference']);
            $this->assertSame('Token app-key-token', $request->header('Authorization')[0]);

            return true;
        });
    }

    public function test_process_souscription_avec_campay_actif_cree_paiement_avec_reference(): void
    {
        ['id' => $clientId, 'user' => $user] = $this->businessClient('pay1@example.com');
        Http::fake([
            'demo.campay.net/api/collect/' => Http::response(['reference' => 'CAMPAY-TX-77', 'status' => 'PENDING'], 200),
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/souscription', [
            'pack' => 'national-essential', 'nom' => 'Jean Dupont',
            'email' => 'pay1@example.com', 'prix' => 25500, 'telephone' => '237690000000',
        ]);

        $response->assertStatus(200)->assertJsonPath('status', 'payment_pending')
            ->assertJsonPath('transaction_id', 'CAMPAY-TX-77');
        $this->assertDatabaseHas('paiements', [
            'client_id' => $clientId, 'transaction_id' => 'CAMPAY-TX-77', 'statut' => 'en_attente',
        ]);
    }

    public function test_webhook_signature_invalide_refuse_401(): void
    {
        $payload = ['transaction_id' => 'TX-A', 'status' => 'SUCCESSFUL', 'event_id' => 'EV-1'];
        $this->postJson('/api/v1/webhooks/campay', $payload, [
            'X-Campay-Signature' => 'sha256=deadbeef',
        ])->assertStatus(401);

        $this->postJson('/api/v1/webhooks/campay', $payload)->assertStatus(401);
        $this->assertDatabaseMissing('webhook_events', ['event_id' => 'EV-1']);
    }

    public function test_webhook_succes_met_a_jour_paiement_et_cree_facture(): void
    {
        ['id' => $clientId] = $this->businessClient('pay2@example.com');
        $this->insertPendingPayment($clientId, 'TX-OK-1', 70000);
        DB::table('abonnements')->insert([
            'client_id' => $clientId, 'pack' => 'national-premium', 'prix' => 70000,
            'date_debut' => now()->format('Y-m-d H:i:s'), 'statut' => 'en_attente',
        ]);

        $payload = ['transaction_id' => 'TX-OK-1', 'status' => 'SUCCESSFUL', 'event_id' => 'EV-OK-1'];
        $this->postJson('/api/v1/webhooks/campay', $payload, $this->signedHeaders($payload))
            ->assertStatus(200)->assertJsonPath('message', 'Paiement mis à jour');

        $this->assertDatabaseHas('paiements', ['transaction_id' => 'TX-OK-1', 'statut' => 'recu']);
        // W-10 : facture + activation abonnement en attente
        $this->assertDatabaseHas('factures', ['numero' => 'FAC-TX-OK-1', 'montant' => 70000]);
        $this->assertDatabaseHas('abonnements', ['client_id' => $clientId, 'statut' => 'actif']);
        $this->assertDatabaseHas('audit_log', ['action' => 'CAMPAY_WEBHOOK']);
    }

    public function test_webhook_meme_event_id_deux_fois_un_seul_effet(): void
    {
        ['id' => $clientId] = $this->businessClient('pay3@example.com');
        $this->insertPendingPayment($clientId, 'TX-IDEM-1', 25000);
        DB::table('abonnements')->insert([
            'client_id' => $clientId, 'pack' => 'national-essential', 'prix' => 25000,
            'date_debut' => now()->format('Y-m-d H:i:s'), 'statut' => 'en_attente',
        ]);

        $payload = ['transaction_id' => 'TX-IDEM-1', 'status' => 'SUCCESSFUL', 'event_id' => 'EV-IDEM-1'];
        $headers = $this->signedHeaders($payload);

        $first = $this->postJson('/api/v1/webhooks/campay', $payload, $headers);
        $first->assertStatus(200)->assertJsonPath('message', 'Paiement mis à jour');
        $this->assertSame(1, DB::table('factures')->where('numero', 'FAC-TX-IDEM-1')->count());
        $this->assertSame(1, DB::table('webhook_events')->where('event_id', 'EV-IDEM-1')->count());

        // 2e envoi du MÊME event_id : 200, aucun nouvel effet
        $second = $this->postJson('/api/v1/webhooks/campay', $payload, $headers);
        $second->assertStatus(200);
        $this->assertSame(1, DB::table('factures')->where('numero', 'FAC-TX-IDEM-1')->count());
        $this->assertSame(1, DB::table('webhook_events')->where('event_id', 'EV-IDEM-1')->count());
        $this->assertSame(1, DB::table('audit_log')->where('action', 'CAMPAY_WEBHOOK')->count());
    }

    public function test_webhook_echec_statut_echoue_sans_facture(): void
    {
        ['id' => $clientId] = $this->businessClient('pay4@example.com');
        $this->insertPendingPayment($clientId, 'TX-FAIL-1', 30000);

        $payload = ['transaction_id' => 'TX-FAIL-1', 'status' => 'FAILED', 'event_id' => 'EV-FAIL-1'];
        $this->postJson('/api/v1/webhooks/campay', $payload, $this->signedHeaders($payload))
            ->assertStatus(200);

        $this->assertDatabaseHas('paiements', ['transaction_id' => 'TX-FAIL-1', 'statut' => 'echoue']);
        $this->assertDatabaseMissing('factures', ['numero' => 'FAC-TX-FAIL-1']);
    }

    public function test_webhook_payload_invalide_400(): void
    {
        $raw = 'not-json{{';
        $this->call('POST', '/api/v1/webhooks/campay', [], [], [],
            array_merge($this->transformHeadersToServerVars([
                'X-Campay-Signature' => 'sha256='.hash_hmac('sha256', $raw, 'test-secret'),
                'CONTENT_TYPE' => 'application/json',
            ])), $raw)->assertStatus(400);
    }

    public function test_webhook_status_inconnu_400(): void
    {
        $payload = ['transaction_id' => 'TX-X', 'status' => 'WHAT', 'event_id' => 'EV-X'];
        $this->postJson('/api/v1/webhooks/campay', $payload, $this->signedHeaders($payload))
            ->assertStatus(400)->assertJsonPath('error', 'Paiement non confirmé');
    }

    public function test_verify_payment_production_statut_campay_recu(): void
    {
        ['id' => $clientId, 'user' => $user] = $this->businessClient('pay5@example.com');
        $subId = (int) DB::table('abonnements')->insertGetId([
            'client_id' => $clientId, 'pack' => 'national-essential', 'prix' => 25000,
            'date_debut' => now()->format('Y-m-d H:i:s'), 'statut' => 'en_attente',
        ]);
        $this->insertPendingPayment($clientId, 'TX-VERIF-1', 25000);

        Http::fake([
            'demo.campay.net/api/transaction/TX-VERIF-1/' => Http::response(['status' => 'SUCCESSFUL'], 200),
        ]);

        $this->actingAs($user)->postJson('/api/v1/paiement/verifier', ['sub_id' => $subId])
            ->assertStatus(200)->assertJsonPath('status', 'paid')
            ->assertJsonPath('transaction_id', 'TX-VERIF-1');
        $this->assertDatabaseHas('paiements', ['transaction_id' => 'TX-VERIF-1', 'statut' => 'recu']);
    }
}
