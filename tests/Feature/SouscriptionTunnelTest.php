<?php

namespace Tests\Feature;

use App\Services\OtpService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Support\BootstrapsBusinessSchema;
use Tests\TestCase;

/**
 * Tunnel complet step-by-step (parité SubscriptionController source) :
 * souscription → paiement vérifié (mode démo) → signature → contrat PDF.
 */
class SouscriptionTunnelTest extends TestCase
{
    use BootstrapsBusinessSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapBusinessSchema();
        // Mode démo (parité CAMPAY_ENABLED=0) : pas d'appel réseau Campay.
        Config::set('fedpawa.CAMPAY_ENABLED', '0');
    }

    public function test_etape1_souscription_cree_abonnement_et_paiement_en_attente(): void
    {
        // Mode démo explicite (audit) : l'OTP n'est exposé dans le JSON QUE si
        // APP_DEBUG + FEDPAWA_DEMO_MODE=true (tests e2e). Cf. AuditFixesTest
        // pour l'assertion inverse (OTP absent par défaut).
        Config::set('fedpawa.FEDPAWA_DEMO_MODE', '1');
        ['id' => $clientId, 'user' => $user] = $this->businessClient('tunnel1@example.com');

        $response = $this->actingAs($user)->postJson('/api/v1/souscription', [
            'service' => 'domiciliation',
            'pack' => 'national-essential',
            'planLabel' => 'PACK ESSENTIAL',
            'prix' => 25500,
            'options' => [],
            'paymentMethod' => 'momo',
            'momo_phone' => '690000000',
            'nom' => 'Jean Dupont',
            'email' => 'tunnel1@example.com',
            'telephone' => '+237690000000',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'payment_pending')
            ->assertJsonStructure(['transaction_id', 'subscription_id', 'client_id', 'signature_otp']);

        $subId = $response->json('subscription_id');
        $this->assertSame($clientId, $response->json('client_id'));
        $this->assertDatabaseHas('abonnements', [
            'id' => $subId, 'client_id' => $clientId, 'pack' => 'national-essential',
            'prix' => 25500, 'statut' => 'en_attente',
        ]);
        $this->assertDatabaseHas('paiements', [
            'client_id' => $clientId, 'statut' => 'en_attente',
            'transaction_id' => 'DEMO-SUB-'.$subId,
        ]);
        // OTP de signature généré et vérifiable (parité renvoi démo)
        $this->assertTrue(OtpService::verify($clientId, (string) $response->json('signature_otp')));
    }

    public function test_etape1_rejette_email_invalide_avec_message_source(): void
    {
        ['user' => $user] = $this->businessClient('tunnel2@example.com');
        $this->actingAs($user)->postJson('/api/v1/souscription', [
            'email' => 'pas-un-email', 'pack' => 'national-essential', 'nom' => 'X', 'prix' => 1000,
        ])->assertStatus(422); // FormRequest (gate sécurité) — message parité via rules
    }

    public function test_etape1_refuse_prix_invalide(): void
    {
        ['user' => $user] = $this->businessClient('tunnel3@example.com');
        $this->actingAs($user)->postJson('/api/v1/souscription', [
            'email' => 'tunnel3@example.com', 'pack' => 'p', 'nom' => 'X', 'prix' => 0,
        ])->assertStatus(422);
    }

    public function test_etape2_verification_paiement_demo_passe_en_paid_et_facture(): void
    {
        ['id' => $clientId, 'user' => $user] = $this->businessClient('tunnel4@example.com');
        $subId = (int) DB::table('abonnements')->insertGetId([
            'client_id' => $clientId, 'pack' => 'national-essential', 'prix' => 50000,
            'date_debut' => now()->format('Y-m-d H:i:s'), 'statut' => 'en_attente',
        ]);
        $tx = 'DEMO-SUB-'.$subId;
        DB::table('paiements')->insert([
            'client_id' => $clientId, 'montant' => 50000, 'mode_paiement' => 'campay',
            'transaction_id' => $tx, 'date' => now()->format('Y-m-d H:i:s'), 'statut' => 'en_attente',
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/paiement/verifier', ['sub_id' => $subId]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('sub_id', $subId)
            ->assertJsonPath('client_id', $clientId)
            ->assertJsonPath('transaction_id', $tx);
        $this->assertEquals(50000.0, $response->json('prix'));

        // W-10 : facture créée + abonnement activé
        $this->assertDatabaseHas('paiements', ['transaction_id' => $tx, 'statut' => 'recu']);
        $this->assertDatabaseHas('factures', ['client_id' => $clientId, 'numero' => 'FAC-'.$tx, 'montant' => 50000]);
        $this->assertDatabaseHas('abonnements', ['id' => $subId, 'statut' => 'actif']);
    }

    public function test_etape3_signature_domiciliation_produit_contrat_pdf(): void
    {
        ['id' => $clientId, 'user' => $user] = $this->businessClient('tunnel5@example.com');
        $subId = (int) DB::table('abonnements')->insertGetId([
            'client_id' => $clientId, 'pack' => 'national-business', 'prix' => 45900,
            'date_debut' => now()->format('Y-m-d H:i:s'), 'statut' => 'en_attente',
            'details' => json_encode(['representant' => 'Jean Dupont', 'qualite' => 'Gérant', 'entreprise' => 'FEDPAWA TEST SARL']),
        ]);
        DB::table('paiements')->insert([
            'client_id' => $clientId, 'montant' => 45900, 'mode_paiement' => 'campay',
            'transaction_id' => 'TX-SIGN-1', 'date' => now()->format('Y-m-d H:i:s'), 'statut' => 'recu',
        ]);
        $otp = OtpService::generate($clientId);

        $response = $this->actingAs($user)->postJson('/api/v1/signature', [
            'sub_id' => $subId, 'client_id' => $clientId, 'otp' => $otp,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Souscription finalisée')
            ->assertJsonStructure(['contrat_url']);

        $this->assertDatabaseHas('abonnements', ['id' => $subId, 'statut' => 'actif']);
        $contrat = DB::table('contrats')->where('client_id', $clientId)->first();
        $this->assertNotNull($contrat);
        $this->assertSame('domiciliation', $contrat->type);
        $this->assertFileExists(storage_path('app/private/documents/contrat_'.$subId.'.pdf'));
        // OTP consommé (parité OtpService::clear)
        $this->assertFalse(OtpService::verify($clientId, $otp));
    }

    public function test_etape3_signature_sans_paiement_recu_refuse_402(): void
    {
        // CAMPAY_ENABLED=1 => la signature exige un paiement 'recu' (parité lane contrat)
        Config::set('fedpawa.CAMPAY_ENABLED', '1');
        ['id' => $clientId, 'user' => $user] = $this->businessClient('tunnel6@example.com');
        $subId = (int) DB::table('abonnements')->insertGetId([
            'client_id' => $clientId, 'pack' => 'national-essential', 'prix' => 25000,
            'date_debut' => now()->format('Y-m-d H:i:s'), 'statut' => 'en_attente',
        ]);
        $otp = OtpService::generate($clientId);

        $this->actingAs($user)->postJson('/api/v1/signature', [
            'sub_id' => $subId, 'client_id' => $clientId, 'otp' => $otp,
        ])->assertStatus(402)->assertJsonPath('error', 'Paiement non confirmé. Utilisez « Vérifier Mon Paiement » avant de signer.');
    }

    public function test_etape3_signature_mauvais_otp_refuse_400(): void
    {
        ['id' => $clientId, 'user' => $user] = $this->businessClient('tunnel7@example.com');
        $subId = (int) DB::table('abonnements')->insertGetId([
            'client_id' => $clientId, 'pack' => 'national-essential', 'prix' => 25000,
            'date_debut' => now()->format('Y-m-d H:i:s'), 'statut' => 'en_attente',
        ]);
        DB::table('paiements')->insert([
            'client_id' => $clientId, 'montant' => 25000, 'mode_paiement' => 'campay',
            'transaction_id' => 'TX-OTP-1', 'date' => now()->format('Y-m-d H:i:s'), 'statut' => 'recu',
        ]);

        $this->actingAs($user)->postJson('/api/v1/signature', [
            'sub_id' => $subId, 'client_id' => $clientId, 'otp' => '000000',
        ])->assertStatus(400)->assertJsonPath('error', 'Signature invalide');
    }

    public function test_signature_service_non_domiciliation_sans_contrat(): void
    {
        ['id' => $clientId, 'user' => $user] = $this->businessClient('tunnel8@example.com');
        $subId = (int) DB::table('abonnements')->insertGetId([
            'client_id' => $clientId, 'pack' => 'creation-entreprise', 'prix' => 150000,
            'date_debut' => now()->format('Y-m-d H:i:s'), 'statut' => 'en_attente',
        ]);
        DB::table('paiements')->insert([
            'client_id' => $clientId, 'montant' => 150000, 'mode_paiement' => 'campay',
            'transaction_id' => 'TX-ND-1', 'date' => now()->format('Y-m-d H:i:s'), 'statut' => 'recu',
        ]);
        $otp = OtpService::generate($clientId);

        $response = $this->actingAs($user)->postJson('/api/v1/signature', [
            'sub_id' => $subId, 'client_id' => $clientId, 'otp' => $otp,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Paiement confirmé — votre dossier est en traitement. Un juriste FEDPAWA vous recontacte sous 24 heures ouvrées.');
        $this->assertDatabaseHas('abonnements', ['id' => $subId, 'statut' => 'actif']);
        $this->assertSame(0, DB::table('contrats')->where('client_id', $clientId)->count());
    }

    public function test_souscription_anonyme_refuse(): void
    {
        $this->postJson('/api/v1/souscription', [
            'email' => 'anon@example.com', 'pack' => 'p', 'nom' => 'X', 'prix' => 1000,
        ])->assertStatus(401); // parité requireClient (401 login_required)
    }
}
