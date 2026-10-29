<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Parité de contrat JSON avec src/Controller/AuthController.php (PHP source) :
 * même input → même JSON, mêmes codes HTTP.
 *
 * - register : 201 {message} | 409 Email déjà utilisé | 400 validation
 * - login    : 200 {message, token, role} | 401 Identifiants invalides
 *              | 429 verrou après 5 échecs (LoginThrottleService, 30 min)
 *              | 401 message dédié compte admin
 * - /me      : 200 {authenticated:true, role, name} | 200 {authenticated:false}
 * - OTP      : 200 vérifié | 400 invalide/expiré | 404 client introuvable
 * - reset    : 200 | 403 session non autorisée (e-mail+OTP stateless)
 */
class AuthParityTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Secret123';

    private array $validPayload = [
        'nom' => 'Jean Dupont',
        'entreprise' => 'FEDPAWA TEST SARL',
        'email' => 'jean@fedpawa.test',
        'telephone' => '+237 690 11 22 33',
        'mot_de_passe' => self::PASSWORD,
    ];

    // ------------------------------------------------------------------
    // REGISTER
    // ------------------------------------------------------------------

    public function test_register_ok_201(): void
    {
        $this->postJson('/api/v1/auth/register', $this->validPayload)
            ->assertStatus(201)
            ->assertExactJson(['message' => 'Compte créé avec succès']);

        $this->assertDatabaseHas('clients', [
            'email' => 'jean@fedpawa.test',
            'nom' => 'Jean Dupont',
            'role' => 'client',
        ]);
    }

    public function test_register_email_deja_utilise_409(): void
    {
        $this->postJson('/api/v1/auth/register', $this->validPayload)->assertStatus(201);

        $this->postJson('/api/v1/auth/register', $this->validPayload)
            ->assertStatus(409)
            ->assertExactJson(['error' => 'Email déjà utilisé']);
    }

    public function test_register_champs_manquants_400(): void
    {
        $this->postJson('/api/v1/auth/register', ['email' => 'x@y.z'])
            ->assertStatus(400)
            ->assertExactJson(['error' => 'Champs manquants']);
    }

    public function test_register_telephone_invalide_400(): void
    {
        $this->postJson('/api/v1/auth/register',
            array_merge($this->validPayload, ['telephone' => '12']))
            ->assertStatus(400)
            ->assertExactJson(['error' => 'Numéro de téléphone invalide (ex: +237 6XX XX XX XX)']);
    }

    public function test_register_email_invalide_400(): void
    {
        $this->postJson('/api/v1/auth/register',
            array_merge($this->validPayload, ['email' => 'pas-un-email']))
            ->assertStatus(400)
            ->assertExactJson(['error' => 'Adresse e-mail invalide']);
    }

    public function test_register_mot_de_passe_faible_400(): void
    {
        $this->postJson('/api/v1/auth/register',
            array_merge($this->validPayload, ['mot_de_passe' => 'faible123']))
            ->assertStatus(400)
            ->assertExactJson(['error' => 'Mot de passe trop faible (min. 8 caractères, 1 majuscule, 1 chiffre)']);
    }

    // ------------------------------------------------------------------
    // LOGIN
    // ------------------------------------------------------------------

    public function test_login_ok_200_avec_token(): void
    {
        $this->postJson('/api/v1/auth/register', $this->validPayload)->assertStatus(201);

        $res = $this->postJson('/api/v1/auth/login', [
            'email' => 'jean@fedpawa.test',
            'mot_de_passe' => self::PASSWORD,
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('message', 'Connexion réussie')
            ->assertJsonPath('role', 'client');
        $this->assertNotEmpty($res->json('token'), 'Token Sanctum attendu');

        // Le token Sanctum authentifie /me
        $this->withToken($res->json('token'))
            ->getJson('/api/v1/auth/me')
            ->assertStatus(200)
            ->assertExactJson([
                'authenticated' => true,
                'role' => 'client',
                'name' => 'Jean Dupont',
                'email' => 'jean@fedpawa.test',
                'kyc_status' => null,
            ]);
    }

    public function test_login_mauvais_mot_de_passe_401(): void
    {
        $this->postJson('/api/v1/auth/register', $this->validPayload)->assertStatus(201);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'jean@fedpawa.test',
            'mot_de_passe' => 'WrongPass1',
        ])
            ->assertStatus(401)
            ->assertExactJson(['error' => 'Identifiants invalides']);
    }

    public function test_login_champs_manquants_400(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'x@y.z'])
            ->assertStatus(400)
            ->assertExactJson(['error' => 'Champs manquants']);
    }

    public function test_login_throttle_bloque_apres_5_echecs_429(): void
    {
        $this->postJson('/api/v1/auth/register', $this->validPayload)->assertStatus(201);

        // 4 échecs → encore 401 « Identifiants invalides »
        for ($i = 1; $i <= 4; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'jean@fedpawa.test',
                'mot_de_passe' => 'WrongPass1',
            ])->assertStatus(401)->assertExactJson(['error' => 'Identifiants invalides']);
        }

        // 5e échec → pose le verrou (recordFailure retourne true) → 429 dédié
        $this->postJson('/api/v1/auth/login', [
            'email' => 'jean@fedpawa.test',
            'mot_de_passe' => 'WrongPass1',
        ])->assertStatus(429)->assertExactJson([
            'error' => 'Trop de tentatives échouées (5). Votre compte est bloqué pendant 30 minutes.',
        ]);

        // Verrou actif → 429 « Réessayez dans N minute(s) », même avec le bon mot de passe
        $res = $this->postJson('/api/v1/auth/login', [
            'email' => 'jean@fedpawa.test',
            'mot_de_passe' => self::PASSWORD,
        ]);
        $res->assertStatus(429);
        $this->assertSame(
            'Trop de tentatives échouées (5). Réessayez dans 30 minute(s) ou contactez le support.',
            $res->json('error')
        );

        // Audit LOGIN_LOCKED + notification interne admin (parité PHP)
        $this->assertDatabaseHas('audit_log', ['action' => 'LOGIN_LOCKED']);
        $this->assertDatabaseHas('internal_messages', [
            'sender_type' => 'system',
            'receiver_type' => 'admin',
        ]);
    }

    public function test_login_succes_reset_throttle(): void
    {
        $this->postJson('/api/v1/auth/register', $this->validPayload)->assertStatus(201);

        // 4 échecs (sous le seuil), puis succès → compteur remis à zéro
        for ($i = 1; $i <= 4; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'jean@fedpawa.test',
                'mot_de_passe' => 'WrongPass1',
            ])->assertStatus(401);
        }
        $this->postJson('/api/v1/auth/login', [
            'email' => 'jean@fedpawa.test',
            'mot_de_passe' => self::PASSWORD,
        ])->assertStatus(200);

        // 4 nouveaux échecs ne suffisent plus à verrouiller (compteur repart à 0)
        for ($i = 1; $i <= 4; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'jean@fedpawa.test',
                'mot_de_passe' => 'WrongPass1',
            ])->assertStatus(401);
        }
        $this->postJson('/api/v1/auth/login', [
            'email' => 'jean@fedpawa.test',
            'mot_de_passe' => self::PASSWORD,
        ])->assertStatus(200)->assertJsonPath('message', 'Connexion réussie');
    }

    public function test_login_compte_admin_message_dedie_401(): void
    {
        // Parité : email dans admins + mauvais mot de passe → message dédié,
        // AUCUN échec enregistré côté throttle 'admin'. (Le seed BE-SCHEMA a
        // déjà mme.law@fedpawa.com → email de test distinct.)
        Admin::firstOrCreate(
            ['email' => 'admin.parity@fedpawa.test'],
            ['mot_de_passe' => Hash::make('AdminSecret1')]
        );

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin.parity@fedpawa.test',
            'mot_de_passe' => 'WrongPass1',
        ])->assertStatus(401)->assertExactJson([
            'error' => 'Ceci est un compte administrateur. Utilisez la page d\'accès admin: /admin_dashboard.html',
        ]);
        $this->assertDatabaseMissing('login_throttle', ['identifier' => 'admin.parity@fedpawa.test']);
    }

    // ------------------------------------------------------------------
    // ME / LOGOUT
    // ------------------------------------------------------------------

    public function test_me_non_authentifie_200_authenticated_false(): void
    {
        // Parité me() PHP : 200 même non connecté, jamais 401
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(200)
            ->assertExactJson(['authenticated' => false]);
    }

    public function test_me_authentifie_200_vs_logout_401(): void
    {
        $login = $this->postJson('/api/v1/auth/login', $this->premiereConnexion());

        // Authentifié : {authenticated: true, role, name}
        $this->withToken($login->json('token'))
            ->getJson('/api/v1/auth/me')
            ->assertStatus(200)
            ->assertExactJson([
                'authenticated' => true,
                'role' => 'client',
                'name' => 'Jean Dupont',
                'email' => 'jean@fedpawa.test',
                'kyc_status' => null,
            ]);

        // Logout révoque le token courant : /me redevient anonyme, /logout 401
        $this->withToken($login->json('token'))
            ->postJson('/api/v1/auth/logout')
            ->assertStatus(200)
            ->assertExactJson(['message' => 'Déconnecté']);

        // Le guard sanctum met l'utilisateur en cache pour la durée du test :
        // on le réinitialise pour simuler une nouvelle requête HTTP côté client.
        $this->app->make('auth')->forgetGuards();

        $this->withToken($login->json('token'))
            ->getJson('/api/v1/auth/me')
            ->assertStatus(200)
            ->assertExactJson(['authenticated' => false]);
        $this->withToken($login->json('token'))
            ->postJson('/api/v1/auth/logout')
            ->assertStatus(401);
    }

    public function test_logout_sans_token_401(): void
    {
        $this->postJson('/api/v1/auth/logout')->assertStatus(401);
    }

    /**
     * /me expose email + kyc_status (parité public/index.php /api/me) : le
     * statut du DERNIER dossier KYC, sinon null. Le tunnel saute l'étape KYC
     * sur cette valeur — sans elle, un client vérifié devait re-téléverser.
     */
    public function test_me_expose_email_et_dernier_kyc_status(): void
    {
        $login = $this->postJson('/api/v1/auth/login', $this->premiereConnexion());
        $clientId = (int) Client::where('email', 'jean@fedpawa.test')->value('id');

        DB::table('kyc')->insert([
            ['client_id' => $clientId, 'statuts' => 'en_cours'],
            ['client_id' => $clientId, 'statuts' => 'valide'],
        ]);

        $this->withToken($login->json('token'))
            ->getJson('/api/v1/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('email', 'jean@fedpawa.test')
            ->assertJsonPath('kyc_status', 'valide');
    }

    // ------------------------------------------------------------------
    // PASSWORD FORGOT / OTP / RESET
    // ------------------------------------------------------------------

    public function test_password_forgot_email_inconnu_message_generique_200(): void
    {
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'inconnu@nulle.part'])
            ->assertStatus(200)
            ->assertExactJson(['message' => 'Si cet e-mail existe, un code de réinitialisation a été envoyé.']);
        $this->assertDatabaseCount('password_resets', 0);
    }

    public function test_password_forgot_sans_email_400(): void
    {
        $this->postJson('/api/v1/auth/password/forgot', [])
            ->assertStatus(400)
            ->assertExactJson(['error' => 'Email requis']);
    }

    public function test_otp_verify_ok_puis_reset_ok(): void
    {
        $this->postJson('/api/v1/auth/register', $this->validPayload)->assertStatus(201);

        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'jean@fedpawa.test'])
            ->assertStatus(200)
            ->assertExactJson(['message' => 'Si cet e-mail existe, un code de réinitialisation a été envoyé.']);

        // Récupère le code injecté (tests : Hash non applicable → stocké en clair)
        $hash = PasswordReset::where('used', 0)->orderByDesc('id')->first()->token_hash;
        $otp = $this->decodeTestOtp($hash);

        // OTP invalide → 400 (parité PHP)
        $this->postJson('/api/v1/auth/otp/verify', [
            'email' => 'jean@fedpawa.test', 'otp' => '000000',
        ])->assertStatus(400)->assertExactJson(['error' => 'Code OTP invalide ou expiré']);

        // OTP correct → 200
        $this->postJson('/api/v1/auth/otp/verify', [
            'email' => 'jean@fedpawa.test', 'otp' => $otp,
        ])->assertStatus(200)->assertExactJson(['message' => 'OTP vérifié avec succès']);

        // Reset avec e-mail + OTP → 200, mot de passe réellement changé
        $newPassword = 'Nouveau123';
        $this->postJson('/api/v1/auth/password/reset', [
            'email' => 'jean@fedpawa.test',
            'otp' => $otp,
            'mot_de_passe' => $newPassword,
        ])->assertStatus(200)->assertExactJson(['message' => 'Mot de passe mis à jour avec succès']);

        $client = Client::where('email', 'jean@fedpawa.test')->first();
        $this->assertTrue(Hash::check($newPassword, $client->mot_de_passe));
        $this->assertSame(1, (int) PasswordReset::where('client_id', $client->id)->first()->used);
        $this->assertDatabaseHas('audit_log', ['action' => 'PASSWORD_RESET']);

        // Ancien mot de passe KO, nouveau OK
        $this->postJson('/api/v1/auth/login', [
            'email' => 'jean@fedpawa.test', 'mot_de_passe' => self::PASSWORD,
        ])->assertStatus(401);
        $this->postJson('/api/v1/auth/login', [
            'email' => 'jean@fedpawa.test', 'mot_de_passe' => $newPassword,
        ])->assertStatus(200);
    }

    public function test_otp_verify_client_introuvable_404(): void
    {
        $this->postJson('/api/v1/auth/otp/verify', [
            'email' => 'inconnu@nulle.part', 'otp' => '123456',
        ])->assertStatus(404)->assertExactJson(['error' => 'Client introuvable']);
    }

    public function test_otp_verify_donnees_invalides_400(): void
    {
        $this->postJson('/api/v1/auth/otp/verify', ['email' => 'jean@fedpawa.test'])
            ->assertStatus(400)
            ->assertExactJson(['error' => 'Données invalides']);
    }

    public function test_reset_sans_otp_valide_403(): void
    {
        $this->postJson('/api/v1/auth/register', $this->validPayload)->assertStatus(201);

        // Parité 403 « Session de réinitialisation non autorisée » (stateless : email+otp)
        $this->postJson('/api/v1/auth/password/reset', [
            'email' => 'jean@fedpawa.test',
            'otp' => '123456',
            'mot_de_passe' => 'Nouveau123',
        ])->assertStatus(403)->assertExactJson(['error' => 'Session de réinitialisation non autorisée']);

        // Reset SANS le moindre otp → même 403 (pas 422 : contrat contrôleur)
        $this->postJson('/api/v1/auth/password/reset', [
            'email' => 'jean@fedpawa.test',
            'mot_de_passe' => 'Nouveau123',
        ])->assertStatus(403);
    }

    public function test_reset_mot_de_passe_trop_faible_400(): void
    {
        $this->postJson('/api/v1/auth/register', $this->validPayload)->assertStatus(201);
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'jean@fedpawa.test'])->assertStatus(200);

        $hash = PasswordReset::where('used', 0)->orderByDesc('id')->first()->token_hash;
        $otp = $this->decodeTestOtp($hash);

        $this->postJson('/api/v1/auth/password/reset', [
            'email' => 'jean@fedpawa.test',
            'otp' => $otp,
            'mot_de_passe' => 'faible',
        ])->assertStatus(400)->assertExactJson(['error' => 'Mot de passe trop faible (min. 8 caractères, 1 majuscule, 1 chiffre)']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Enregistre un client et renvoie les identifiants de login. */
    private function premiereConnexion(): array
    {
        $this->postJson('/api/v1/auth/register', $this->validPayload)->assertStatus(201);

        return [
            'email' => 'jean@fedpawa.test',
            'mot_de_passe' => self::PASSWORD,
        ];
    }

    /**
     * Le code OTP est hashé en prod (Hash::make). En test, on le récupère
     * depuis la log (chaîne « [OTP-DEV] code pour <email> : <code> »),
     * parité error_log du PHP source.
     */
    private function decodeTestOtp(string $hash): string
    {
        $log = file_get_contents(storage_path('logs/laravel.log'));
        preg_match_all('/\[OTP-DEV\] code pour ([^\s]+) : (\d{6})/', $log ?: '', $m);
        $this->assertNotEmpty($m[2], 'Code OTP de test introuvable dans la log');

        $otp = $m[2][count($m[2]) - 1];
        $this->assertTrue(Hash::check($otp, $hash), 'OTP de la log ne correspond pas au hash');

        return $otp;
    }
}
