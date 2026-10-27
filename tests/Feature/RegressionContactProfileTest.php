<?php

namespace Tests\Feature;

use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Tests\Support\BootstrapsBusinessSchema;
use Tests\Support\BootstrapsEdgeSchema;
use Tests\TestCase;

/**
 * Régressions signalées par les test-supervisors (FIX-BACK-NOTIF) :
 * 1. sendContactNotification() existait en appel mais pas en implémentation
 *    (fatal avalé par try/catch → notif admin jamais envoyée) ;
 * 2. honeypot 'site' = espaces : empty(' ') est false en PHP → une ligne
 *    était insérée en base (divergence legacy : refus silencieux = 200 SANS
 *    insertion) ;
 * 3. profil/update : téléphone non validé ('abc!' accepté) alors que
 *    register valide — même règle regex appliquée.
 */
class RegressionContactProfileTest extends TestCase
{
    use BootstrapsBusinessSchema, BootstrapsEdgeSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapBusinessSchema();
        $this->bootstrapEdgeSchema();
    }

    // --- 1. NotificationService::sendContactNotification ---

    public function test_send_contact_notification_existe_et_retourne_bool(): void
    {
        $svc = app(NotificationService::class);

        // la méthode doit exister et être appelable sans fatal (bug historique :
        // Call to undefined method avalé par le try/catch du contrôleur)
        $this->assertTrue(method_exists($svc, 'sendContactNotification'));

        $ok = $svc->sendContactNotification(
            'contact@fedpawa.com', 'Sujet X', 'Awa', 'awa@example.com',
            '+237 690 00 00 00', 'Bonjour, ceci est un message.'
        );
        $this->assertIsBool($ok); // SMTP non configuré en test => false, sans exception
    }

    public function test_contact_store_appelle_send_contact_notification(): void
    {
        $spy = $this->mock(NotificationService::class);
        $spy->shouldReceive('sendContactNotification')
            ->once()
            ->with(
                'contact@fedpawa.com',
                'Demande info',
                'Awa',
                'awa@example.com',
                '+237 690 00 00 00',
                'Bonjour'
            )
            ->andReturn(true);
        $this->app->instance(NotificationService::class, $spy);

        $this->postJson('/api/v1/contact', [
            'nom' => 'Awa',
            'email' => 'awa@example.com',
            'telephone' => '+237 690 00 00 00',
            'sujet' => 'Demande info',
            'message' => 'Bonjour',
        ])->assertStatus(200)->assertJson([
            'message' => 'Merci ! Votre message a bien été envoyé. Nous vous répondrons sous 24h ouvrées.',
        ]);

        $this->assertDatabaseHas('contact_messages', ['email' => 'awa@example.com']);
    }

    // --- 2. Honeypot espaces : 200 SANS insertion ---

    public function test_contact_honeypot_espaces_aucune_insertion(): void
    {
        $res = $this->postJson('/api/v1/contact', [
            'nom' => 'Bot',
            'email' => 'bot-espaces@spam.example',
            'sujet' => 'x',
            'message' => 'spam',
            'site' => '   ', // espaces : trim(' ') !== '' → refus silencieux
        ]);

        $res->assertStatus(200)
            ->assertJson(['message' => 'Merci ! Votre message a bien été envoyé.']);
        $this->assertDatabaseMissing('contact_messages', ['email' => 'bot-espaces@spam.example']);
        $this->assertSame(0, (int) DB::table('contact_messages')->where('email', 'bot-espaces@spam.example')->count());
    }

    // --- 3. Profil téléphone : même règle que register ---

    public function test_profil_update_telephone_invalide_rejete(): void
    {
        ['user' => $user] = $this->businessClient('profil-tel@example.com');

        // 'abc!' : accepté avant le fix, rejeté maintenant (422, parité register)
        $this->actingAs($user)->postJson('/api/v1/profil/update', [
            'nom' => 'Jean Dupont',
            'email' => 'profil-tel@example.com',
            'telephone' => 'abc!',
        ])->assertStatus(422)->assertJsonValidationErrors('telephone');

        $this->assertDatabaseMissing('clients', ['email' => 'profil-tel@example.com', 'telephone' => 'abc!']);
    }

    public function test_profil_update_telephone_valide_passe(): void
    {
        ['user' => $user, 'id' => $id] = $this->businessClient('profil-tel-ok@example.com');

        $this->actingAs($user)->postJson('/api/v1/profil/update', [
            'nom' => 'Jean Dupont',
            'email' => 'profil-tel-ok@example.com',
            'telephone' => '+237 690 11 22 33',
        ])->assertStatus(200)->assertJson(['message' => 'Profil mis à jour']);

        $this->assertDatabaseHas('clients', ['id' => $id, 'telephone' => '+237 690 11 22 33']);
    }
}
