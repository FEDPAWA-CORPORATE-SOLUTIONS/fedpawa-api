<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BootstrapsBusinessSchema;
use Tests\Support\BootstrapsEdgeSchema;
use Tests\TestCase;

/**
 * Parité SupportController : POST /api/v1/support + GET /api/v1/support/tickets.
 * - 401 sans token Sanctum ; - 200 création ('Ticket de support envoyé') ;
 * - 422 champs manquants ; - listing 50 derniers, colonnes identiques.
 */
class EdgeSupportTest extends TestCase
{
    use BootstrapsBusinessSchema, BootstrapsEdgeSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapBusinessSchema();
        $this->bootstrapEdgeSchema();
    }

    public function test_support_tickets_sans_auth_401(): void
    {
        $this->getJson('/api/v1/support/tickets')->assertStatus(401);
        $this->postJson('/api/v1/support', ['sujet' => 'x', 'message' => 'y'])->assertStatus(401);
    }

    public function test_support_store_200_et_listing(): void
    {
        ['user' => $user] = $this->businessClient('support@example.com');

        $res = $this->actingAs($user)->postJson('/api/v1/support', [
            'sujet' => 'Problème de facturation',
            'message' => 'Ma facture de mars est erronée.',
        ]);

        $res->assertStatus(200)->assertJson(['message' => 'Ticket de support envoyé']);

        $this->assertDatabaseHas('tickets_support', [
            'sujet' => 'Problème de facturation',
            'statut' => 'open',
        ]);

        $list = $this->actingAs($user)->getJson('/api/v1/support/tickets');
        $list->assertStatus(200)
            ->assertJsonStructure(['tickets' => [['id', 'sujet', 'message', 'statut', 'created_at']]])
            ->assertJsonPath('tickets.0.reponse', null) // colonne présente, null tant qu'admin n'a pas répondu
            ->assertJsonPath('tickets.0.reponse_at', null);
        $this->assertSame(1, count($list->json('tickets')));
    }

    public function test_support_store_validation_422(): void
    {
        ['user' => $user] = $this->businessClient('support2@example.com');

        $this->actingAs($user)->postJson('/api/v1/support', ['sujet' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sujet', 'message']);
    }

    public function test_support_tickets_isoles_par_client(): void
    {
        ['user' => $a] = $this->businessClient('iso-a@example.com');
        ['user' => $b, 'id' => $idB] = $this->businessClient('iso-b@example.com');

        DB::table('tickets_support')->insert([
            'client_id' => $idB, 'sujet' => 'Ticket B', 'message' => 'secret',
            'statut' => 'open', 'created_at' => now()->format('Y-m-d H:i:s'),
        ]);

        $res = $this->actingAs($a)->getJson('/api/v1/support/tickets');
        $res->assertStatus(200);
        $this->assertSame(0, count($res->json('tickets'))); // A ne voit pas B
    }
}
