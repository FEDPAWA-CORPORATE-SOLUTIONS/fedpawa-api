<?php

namespace Tests\Feature;

use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BootstrapsBusinessSchema;
use Tests\Support\BootstrapsEdgeSchema;
use Tests\TestCase;

/**
 * Parité ContactController : POST /api/v1/contact (public, anonyme).
 * - 200 + message identique legacy ; - 422 validation (FormRequest) ;
 * - honeypot 'site' rempli => succès SANS insertion ; - notification
 * admin best-effort (NotificationService stubé, jamais bloquant).
 */
class EdgeContactTest extends TestCase
{
    use BootstrapsBusinessSchema, BootstrapsEdgeSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapBusinessSchema();
        $this->bootstrapEdgeSchema();
    }

    public function test_contact_store_repond_200_et_insere(): void
    {
        $payload = [
            'nom' => 'Awa Bénédicte',
            'email' => 'awa@example.com',
            'telephone' => '+237 690 00 00 00',
            'sujet' => 'Demande d\'information',
            'message' => 'Bonjour, je souhaite des informations sur vos packs.',
        ];

        $res = $this->postJson('/api/v1/contact', $payload);

        $res->assertStatus(200)
            ->assertJson(['message' => 'Merci ! Votre message a bien été envoyé. Nous vous répondrons sous 24h ouvrées.']);

        $this->assertDatabaseHas('contact_messages', [
            'nom' => 'Awa Bénédicte',
            'email' => 'awa@example.com',
            'sujet' => 'Demande d\'information',
            'statut' => 'nouveau',
        ]);
    }

    public function test_contact_validation_422(): void
    {
        $this->postJson('/api/v1/contact', ['nom' => '', 'email' => 'pas-un-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nom', 'email', 'sujet', 'message']);
    }

    public function test_contact_honeypot_site_aucune_insertion(): void
    {
        $res = $this->postJson('/api/v1/contact', [
            'nom' => 'Bot',
            'email' => 'bot@spam.example',
            'sujet' => 'x',
            'message' => 'spam',
            'site' => 'http://spam.example', // honeypot rempli
        ]);

        $res->assertStatus(200)
            ->assertJson(['message' => 'Merci ! Votre message a bien été envoyé.']);
        $this->assertDatabaseMissing('contact_messages', ['email' => 'bot@spam.example']);
    }

    public function test_contact_notification_admin_best_effort(): void
    {
        $spy = $this->mock(NotificationService::class);
        $spy->shouldReceive('sendContactNotification')->once()->andReturn(false);

        $this->app->instance(NotificationService::class, $spy);

        $this->postJson('/api/v1/contact', [
            'nom' => 'Awa',
            'email' => 'awa@example.com',
            'sujet' => 'Test',
            'message' => 'Bonjour',
        ])->assertStatus(200); // l'échec mail ne fait PAS échouer la requête
    }
}
