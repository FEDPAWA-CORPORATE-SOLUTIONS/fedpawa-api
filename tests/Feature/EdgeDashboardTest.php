<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\BootstrapsBusinessSchema;
use Tests\Support\BootstrapsEdgeSchema;
use Tests\TestCase;

/**
 * Parité DashboardController : GET /api/v1/dashboard + POST /api/v1/profil/update.
 * - 401 sans auth ; - agrégat client/subscription/factures/contrats/
 *   courriers/kyc/pastSubscriptions ; - W-9 actif d'abord ; - 409 email pris ;
 * - 422 validation.
 */
class EdgeDashboardTest extends TestCase
{
    use BootstrapsBusinessSchema, BootstrapsEdgeSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapBusinessSchema();
        $this->bootstrapEdgeSchema();
    }

    public function test_dashboard_sans_auth_401(): void
    {
        $this->getJson('/api/v1/dashboard')->assertStatus(401);
        $this->postJson('/api/v1/profil/update', [])->assertStatus(401);
    }

    public function test_dashboard_agregat_complet(): void
    {
        ['user' => $user, 'id' => $id] = $this->businessClient('dash@example.com');
        $now = now()->format('Y-m-d H:i:s');

        DB::table('abonnements')->insert([
            ['client_id' => $id, 'pack' => 'Standard', 'prix' => 50000, 'engagement' => 12, 'date_debut' => '2026-01-01 00:00:00', 'date_fin' => '2026-12-31 00:00:00', 'statut' => 'expiré'],
            ['client_id' => $id, 'pack' => 'Premium', 'prix' => 90000, 'engagement' => 12, 'date_debut' => '2026-06-01 00:00:00', 'date_fin' => '2027-06-01 00:00:00', 'statut' => 'actif'],
        ]);
        DB::table('factures')->insert([
            'client_id' => $id, 'numero' => 'FAC-2026-001', 'montant' => 50000, 'date' => $now, 'lien_pdf' => null,
        ]);
        DB::table('contrats')->insert([
            'client_id' => $id, 'type' => 'domiciliation', 'fichier' => 'documents/contrat_1.pdf', 'date_signature' => $now,
        ]);
        DB::table('courriers')->insert([
            'client_id' => $id, 'date_reception' => $now, 'scan' => 'client_'.$id.'/scan.pdf', 'statut' => 'non_lu',
        ]);
        DB::table('kyc')->insert([
            'client_id' => $id, 'piece_identite' => 'client_'.$id.'/id.pdf', 'statuts' => 'approuvé', 'date_soumission' => $now,
        ]);

        $res = $this->actingAs($user)->getJson('/api/v1/dashboard');

        $res->assertStatus(200)
            ->assertJsonStructure(['client', 'subscription', 'factures', 'contrats', 'courriers', 'kyc', 'pastSubscriptions']);

        // W-9 : l'abonnement ACTIF prime (même si plus ancien id)
        $this->assertSame('Premium', $res->json('subscription.pack'));
        $this->assertSame('Standard', $res->json('pastSubscriptions.0.pack'));
        $this->assertSame(1, count($res->json('pastSubscriptions')));
        $this->assertSame('Jean Dupont', $res->json('client.nom'));
        $this->assertSame('approuvé', $res->json('kyc.statuts'));
        $this->assertSame(1, count($res->json('factures')));
        $this->assertSame(1, count($res->json('contrats')));
        $this->assertSame(1, count($res->json('courriers')));
    }

    public function test_dashboard_sans_abonnement_kyc_defaut(): void
    {
        ['user' => $user] = $this->businessClient('dash-vide@example.com');

        $res = $this->actingAs($user)->getJson('/api/v1/dashboard');
        $res->assertStatus(200);
        $this->assertNull($res->json('subscription'));
        $this->assertSame(['statuts' => 'en_attente'], $res->json('kyc'));
        $this->assertSame([], $res->json('factures'));
    }

    public function test_profil_update_200_et_409_email_pris(): void
    {
        ['user' => $user, 'id' => $id] = $this->businessClient('profil@example.com');
        $this->businessClient('pris@example.com');

        // 200 : mise à jour propre
        $this->actingAs($user)->postJson('/api/v1/profil/update', [
            'nom' => 'Nouveau Nom', 'email' => 'profil@example.com',
            'entreprise' => 'Nouvelle Entreprise', 'telephone' => '+237 690 11 22 33', 'adresse' => 'Douala',
        ])->assertStatus(200)->assertJson(['message' => 'Profil mis à jour']);

        $this->assertDatabaseHas('clients', ['id' => $id, 'nom' => 'Nouveau Nom', 'entreprise' => 'Nouvelle Entreprise']);

        // 409 : email appartenant à un autre client
        $this->actingAs($user)->postJson('/api/v1/profil/update', [
            'nom' => 'X', 'email' => 'pris@example.com',
        ])->assertStatus(409)->assertJson(['error' => 'Cet e-mail est déjà utilisé']);
    }

    public function test_profil_update_validation_422(): void
    {
        ['user' => $user] = $this->businessClient('profil-val@example.com');
        $this->actingAs($user)->postJson('/api/v1/profil/update', ['nom' => '', 'email' => 'invalide'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nom', 'email']);
    }
}
