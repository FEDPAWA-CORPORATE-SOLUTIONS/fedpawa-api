<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Services\OtpService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Support\BootstrapsBusinessSchema;
use Tests\TestCase;

/**
 * Correctifs d'audit (OTP/API, hashing admin, Campay non configuré) :
 *
 * 1. OTP de signature JAMAIS dans la réponse JSON — sauf mode démo explicite
 *    (APP_DEBUG + FEDPAWA_DEMO_MODE=true). Le code part uniquement par e-mail
 *    (en test : tracé dans la log [OTP-SIGN], parité [OTP-DEV] du reset).
 * 2. Login admin avec hash argon2id (seed PHP source) : plus de 500 —
 *    vérification multi-format (bcrypt puis password_verify argon2id).
 * 3. CAMPAY_ENABLED=1 sans credentials Campay → réponse d'erreur claire
 *    (503), pas un 500 avec trace.
 */
class AuditFixesTest extends TestCase
{
    use BootstrapsBusinessSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapBusinessSchema();
        Config::set('fedpawa.CAMPAY_ENABLED', '0');
    }

    // ==================================================================
    // 1) OTP absent de la réponse JSON (faille audit)
    // ==================================================================

    public function test_souscription_ne_renvoie_pas_l_otp_par_defaut(): void
    {
        ['id' => $clientId, 'user' => $user] = $this->businessClient('audit-otp1@example.com');

        $response = $this->actingAs($user)->postJson('/api/v1/souscription', [
            'service' => 'domiciliation',
            'pack' => 'national-essential',
            'planLabel' => 'PACK ESSENTIAL',
            'prix' => 25500,
            'options' => [],
            'paymentMethod' => 'momo',
            'momo_phone' => '690000000',
            'nom' => 'Jean Dupont',
            'email' => 'audit-otp1@example.com',
            'telephone' => '+237****0000',
        ]);

        $response->assertStatus(200)->assertJsonPath('status', 'payment_pending');
        $json = $response->json();
        $this->assertArrayNotHasKey('signature_otp', $json, 'OTP ne doit JAMAIS apparaître dans la réponse JSON');
        $this->assertArrayNotHasKey('otp', $json);

        // Un OTP a bien été généré côté serveur (vérifiable via OtpService),
        // il est simplement passé par le canal e-mail/log, pas par le JSON.
        $hash = \Illuminate\Support\Facades\Cache::get('otp_'.$clientId);
        $this->assertNotNull($hash, 'OTP doit être généré et stocké (hash)');
    }

    public function test_souscription_renvoie_l_otp_uniquement_en_mode_demo_explicite(): void
    {
        Config::set('fedpawa.FEDPAWA_DEMO_MODE', '1'); // + APP_DEBUG=true (env test)
        ['id' => $clientId, 'user' => $user] = $this->businessClient('audit-otp2@example.com');

        $response = $this->actingAs($user)->postJson('/api/v1/souscription', [
            'service' => 'domiciliation',
            'pack' => 'national-essential',
            'planLabel' => 'PACK ESSENTIAL',
            'prix' => 25500,
            'options' => [],
            'paymentMethod' => 'momo',
            'momo_phone' => '690000000',
            'nom' => 'Jean Dupont',
            'email' => 'audit-otp2@example.com',
            'telephone' => '+237****0000',
        ]);

        $response->assertStatus(200);
        $otp = (string) $response->json('signature_otp');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $otp, 'Mode démo : OTP 6 chiffres exposé pour e2e');
        $this->assertTrue(OtpService::verify($clientId, $otp));
    }

    public function test_mode_demo_sans_app_debug_ne_renvoie_pas_l_otp(): void
    {
        // FEDPAWA_DEMO_MODE=true mais APP_DEBUG=false => jamais d'OTP en JSON
        Config::set('app.debug', false);
        Config::set('fedpawa.FEDPAWA_DEMO_MODE', '1');
        ['user' => $user] = $this->businessClient('audit-otp3@example.com');

        $response = $this->actingAs($user)->postJson('/api/v1/souscription', [
            'service' => 'domiciliation',
            'pack' => 'national-essential',
            'planLabel' => 'PACK ESSENTIAL',
            'prix' => 25500,
            'options' => [],
            'paymentMethod' => 'momo',
            'momo_phone' => '690000000',
            'nom' => 'Jean Dupont',
            'email' => 'audit-otp3@example.com',
            'telephone' => '+237****0000',
        ]);

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('signature_otp', $response->json());
    }

    // ==================================================================
    // 2) Login admin avec hash argon2id (seed PHP source) — plus de 500
    // ==================================================================

    public function test_login_admin_hash_argon2id_reussi(): void
    {
        // Hash argon2id tel que seedé par le PHP source (password_hash
        // default PHP 8+ = argon2id sur la box source)
        $admin = Admin::firstOrCreate(
            ['email' => 'admin.argon2@fedpawa.test'],
            ['mot_de_passe' => password_hash('AdminArgon2Secret', PASSWORD_ARGON2ID)]
        );
        $this->assertStringStartsWith('$argon2id$', $admin->mot_de_passe);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin.argon2@fedpawa.test',
            'mot_de_passe' => 'AdminArgon2Secret',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Connexion réussie')
            ->assertJsonPath('role', 'admin')
            ->assertJsonPath('redirect', 'admin_dashboard.html');
        $this->assertDatabaseHas('audit_log', ['action' => 'ADMIN_LOGIN_via_client_form', 'user_id' => $admin->id]);
    }

    public function test_login_admin_argon2id_mauvais_mot_de_passe_401_pas_500(): void
    {
        Admin::firstOrCreate(
            ['email' => 'admin.argon2b@fedpawa.test'],
            ['mot_de_passe' => password_hash('AdminArgon2Secret', PASSWORD_ARGON2ID)]
        );

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin.argon2b@fedpawa.test',
            'mot_de_passe' => 'WrongPassword',
        ]);

        $response->assertStatus(401)->assertExactJson([
            'error' => 'Ceci est un compte administrateur. Utilisez la page d\'accès admin: /admin_dashboard.html',
        ]);
    }

    public function test_login_admin_seed_reel_argon2id_reussi(): void
    {
        // Admin seedé par la migration (hash argon2id du PHP source) :
        // mme.law@fedpawa.com — mot de passe réel inconnu, on resse le même
        // format via un hash argon2id valide pour prouver le chemin complet.
        Admin::updateOrCreate(
            ['email' => 'mme.law@fedpawa.com'],
            ['mot_de_passe' => password_hash('LawSecret123', PASSWORD_ARGON2ID)]
        );

        $this->postJson('/api/v1/auth/login', [
            'email' => 'mme.law@fedpawa.com',
            'mot_de_passe' => 'LawSecret123',
        ])->assertStatus(200)->assertJsonPath('role', 'admin');
    }

    // ==================================================================
    // 3) Campay activé mais non configuré → erreur claire, pas un 500
    // ==================================================================

    public function test_verifier_avec_campay_active_non_configure_repond_503_clair(): void
    {
        Config::set('fedpawa.CAMPAY_ENABLED', '1');
        Config::set('fedpawa.CAMPAY_TOKEN', '');
        Config::set('fedpawa.CAMPAY_USER', '');
        Config::set('fedpawa.CAMPAY_PASSWORD', '');

        ['id' => $clientId, 'user' => $user] = $this->businessClient('audit-campay1@example.com');
        $subId = (int) DB::table('abonnements')->insertGetId([
            'client_id' => $clientId, 'pack' => 'national-essential', 'prix' => 50000,
            'date_debut' => now()->format('Y-m-d H:i:s'), 'statut' => 'en_attente',
        ]);
        DB::table('paiements')->insert([
            'client_id' => $clientId, 'montant' => 50000, 'mode_paiement' => 'campay',
            'transaction_id' => 'TX-NOCAMPAY-1', 'date' => now()->format('Y-m-d H:i:s'), 'statut' => 'en_attente',
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/paiement/verifier', ['sub_id' => $subId]);

        $response->assertStatus(503)->assertJsonPath(
            'error',
            "Le service de paiement Campay n'est pas configuré. Contactez le support (CAMPAY_TOKEN ou CAMPAY_USER/CAMPAY_PASSWORD manquants)."
        );
        // Aucune trace d'exception : le paiement reste en_attente, pas de 500
        $this->assertDatabaseHas('paiements', ['transaction_id' => 'TX-NOCAMPAY-1', 'statut' => 'en_attente']);
    }

    public function test_souscription_avec_campay_active_non_configure_repond_503_clair(): void
    {
        Config::set('fedpawa.CAMPAY_ENABLED', '1');
        Config::set('fedpawa.CAMPAY_TOKEN', '');
        Config::set('fedpawa.CAMPAY_USER', '');
        Config::set('fedpawa.CAMPAY_PASSWORD', '');

        ['user' => $user] = $this->businessClient('audit-campay2@example.com');

        $response = $this->actingAs($user)->postJson('/api/v1/souscription', [
            'service' => 'domiciliation',
            'pack' => 'national-essential',
            'planLabel' => 'PACK ESSENTIAL',
            'prix' => 25500,
            'options' => [],
            'paymentMethod' => 'momo',
            'momo_phone' => '690000000',
            'nom' => 'Jean Dupont',
            'email' => 'audit-campay2@example.com',
            'telephone' => '+237****0000',
        ]);

        $response->assertStatus(503)->assertJsonPath('status', 'payment_unavailable')
            ->assertJsonPath(
                'error',
                "Le service de paiement Campay n'est pas configuré. Contactez le support (CAMPAY_TOKEN ou CAMPAY_USER/CAMPAY_PASSWORD manquants)."
            );
    }

    public function test_verifier_avec_credentials_campay_configures_appelle_checkstatus(): void
    {
        // Config réelle (token) => comportement nominal préservé : checkStatus
        // appelé (Http::fake), pas de dégradation 503.
        Config::set('fedpawa.CAMPAY_ENABLED', '1');
        Config::set('fedpawa.CAMPAY_TOKEN', 'real-token');
        \Illuminate\Support\Facades\Http::fake([
            'demo.campay.net/api/transaction/TX-REAL-1/' => \Illuminate\Support\Facades\Http::response(['status' => 'SUCCESSFUL'], 200),
        ]);

        ['id' => $clientId, 'user' => $user] = $this->businessClient('audit-campay3@example.com');
        $subId = (int) DB::table('abonnements')->insertGetId([
            'client_id' => $clientId, 'pack' => 'national-essential', 'prix' => 50000,
            'date_debut' => now()->format('Y-m-d H:i:s'), 'statut' => 'en_attente',
        ]);
        DB::table('paiements')->insert([
            'client_id' => $clientId, 'montant' => 50000, 'mode_paiement' => 'campay',
            'transaction_id' => 'TX-REAL-1', 'date' => now()->format('Y-m-d H:i:s'), 'statut' => 'en_attente',
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/paiement/verifier', ['sub_id' => $subId]);

        $response->assertStatus(200)->assertJsonPath('status', 'paid');
        \Illuminate\Support\Facades\Http::assertSent(
            fn ($request) => $request->url() === 'https://demo.campay.net/api/transaction/TX-REAL-1/'
        );
    }
}
