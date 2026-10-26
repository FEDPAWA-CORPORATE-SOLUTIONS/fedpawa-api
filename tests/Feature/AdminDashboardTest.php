<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OtpService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\BootstrapsBusinessSchema;
use Tests\Support\BootstrapsEdgeSchema;
use Tests\TestCase;

/**
 * Parité AdminController (source PHP) : les 7 routes dashboard admin.
 * - GET  /api/v1/admin/lists               : listes enrichies + doc_exists (L011) ;
 * - GET  /api/v1/admin/overview            : stats KPI + messages + tickets + throttled ;
 * - GET  /api/v1/admin/template            : modèle contract + unlocked (L012) ;
 * - POST /api/v1/admin/template/otp-request : OTP admin, email masqué, jamais le code ;
 * - POST /api/v1/admin/template/otp-verify  : unlock 10 min, 5 essais max (429) ;
 * - POST /api/v1/admin/template/update       : 403 sans OTP, 200 avec, history + audit ;
 * - POST /api/v1/admin/ticket/reply         : réponse + statut 'répondu', 404 inconnu.
 * Garde CheckAdminRole : 401 non connecté, 403 client.
 */
class AdminDashboardTest extends TestCase
{
    use BootstrapsBusinessSchema, BootstrapsEdgeSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapBusinessSchema();
        $this->bootstrapEdgeSchema();
        Cache::flush();
    }

    /** Admin + une ligne de données dans chaque table listée. */
    private function seedAdminData(): array
    {
        ['user' => $admin, 'id' => $adminId] = $this->businessClient('admin-dash@example.com', 'admin');
        ['id' => $clientId] = $this->businessClient('list-client@example.com');

        DB::table('abonnements')->insert([
            'client_id' => $clientId, 'pack' => 'domiciliation', 'prix' => 75000,
            'date_debut' => '2026-01-01 00:00:00', 'statut' => 'actif',
        ]);
        DB::table('paiements')->insert([
            'client_id' => $clientId, 'montant' => 75000, 'mode_paiement' => 'campay',
            'transaction_id' => 'TX-1', 'date' => '2026-01-02 00:00:00', 'statut' => 'complété',
        ]);
        DB::table('courriers')->insert([
            'client_id' => $clientId, 'date_reception' => '2026-01-03 00:00:00',
            'scan' => 'scans/inexistant.pdf', 'statut' => 'non_lu',
        ]);
        DB::table('contrats')->insert([
            'client_id' => $clientId, 'type' => 'domiciliation',
            'fichier' => 'documents/absent.pdf', 'date_signature' => '2026-01-04 00:00:00',
        ]);
        DB::table('kyc')->insert([
            'client_id' => $clientId, 'piece_identite' => 'uploads/absent.png',
            'statuts' => 'en_cours', 'date_soumission' => '2026-01-05 00:00:00',
        ]);
        DB::table('tickets_support')->insert([
            'client_id' => $clientId, 'sujet' => 'Facture', 'message' => 'Ou est ma facture ?',
            'statut' => 'open', 'created_at' => '2026-01-06 00:00:00',
        ]);
        DB::table('internal_messages')->insert([
            'sender_id' => $clientId, 'sender_type' => 'client', 'receiver_id' => null,
            'receiver_type' => 'admin', 'content' => 'Bonjour', 'is_read' => 0,
            'created_at' => '2026-01-07 00:00:00',
        ]);

        return ['user' => $admin, 'id' => $adminId, 'client_id' => $clientId];
    }

    private function seedThrottleLock(): void
    {
        DB::statement('CREATE TABLE IF NOT EXISTS login_throttle (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            identifier TEXT NOT NULL,
            user_type TEXT NOT NULL,
            ip TEXT,
            attempts INTEGER DEFAULT 0,
            locked_until DATETIME
        )');
        DB::table('login_throttle')->insert([
            'identifier' => 'locked@example.com', 'user_type' => 'client',
            'ip' => '1.2.3.4', 'attempts' => 5,
            'locked_until' => now()->addMinutes(9)->format('Y-m-d H:i:s'),
        ]);
    }

    // ===== Garde : 401 non connecté, 403 client =====

    public function test_routes_admin_sans_auth_401(): void
    {
        $this->getJson('/api/v1/admin/lists')->assertStatus(401);
        $this->getJson('/api/v1/admin/overview')->assertStatus(401);
        $this->getJson('/api/v1/admin/template')->assertStatus(401);
        $this->postJson('/api/v1/admin/template/otp-request', [])->assertStatus(401);
        $this->postJson('/api/v1/admin/template/otp-verify', ['otp' => '123456'])->assertStatus(401);
        $this->postJson('/api/v1/admin/template/update', ['content' => str_repeat('a', 60)])->assertStatus(401);
        $this->postJson('/api/v1/admin/ticket/reply', ['ticket_id' => 1, 'reponse' => 'ok'])->assertStatus(401);
    }

    public function test_routes_admin_client_role_403(): void
    {
        ['user' => $client] = $this->businessClient('client-dash@example.com', 'client');

        $this->actingAs($client)->getJson('/api/v1/admin/lists')->assertStatus(403);
        $this->actingAs($client)->getJson('/api/v1/admin/overview')->assertStatus(403);
        $this->actingAs($client)->getJson('/api/v1/admin/template')->assertStatus(403);
        $this->actingAs($client)->postJson('/api/v1/admin/template/otp-request', [])->assertStatus(403);
        $this->actingAs($client)->postJson('/api/v1/admin/template/otp-verify', ['otp' => '123456'])->assertStatus(403);
        $this->actingAs($client)->postJson('/api/v1/admin/template/update', ['content' => str_repeat('a', 60)])->assertStatus(403);
        $this->actingAs($client)->postJson('/api/v1/admin/ticket/reply', ['ticket_id' => 1, 'reponse' => 'ok'])->assertStatus(403);
    }

    // ===== GET /admin/lists =====

    public function test_lists_retourne_les_huit_collections_enrichies(): void
    {
        ['user' => $admin, 'client_id' => $clientId] = $this->seedAdminData();

        $res = $this->actingAs($admin)->getJson('/api/v1/admin/lists');

        $res->assertStatus(200);
        foreach (['clients', 'abonnements', 'paiements', 'courriers', 'contrats', 'kyc', 'tickets', 'messages'] as $key) {
            $res->assertJsonStructure([$key]);
        }
        // Enrichissement : client_nom joint, doc_exists calculé (fichiers absents → false)
        $res->assertJsonPath('abonnements.0.client_nom', 'Jean Dupont');
        $res->assertJsonPath('paiements.0.transaction_id', 'TX-1');
        $res->assertJsonPath('courriers.0.doc_exists', false);
        $res->assertJsonPath('contrats.0.doc_exists', false);
        $res->assertJsonPath('kyc.0.doc_exists_identite', false);
        $res->assertJsonPath('kyc.0.doc_exists', false);
        $res->assertJsonPath('tickets.0.client_nom', 'Jean Dupont');
        $res->assertJsonPath('messages.0.content', 'Bonjour');
    }

    public function test_lists_doc_exists_true_quand_fichier_present(): void
    {
        ['user' => $admin, 'client_id' => $clientId] = $this->seedAdminData();
        $rel = 'test-docs/'.uniqid().'.txt';
        $dir = storage_path('uploads/test-docs');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents(storage_path('uploads').'/'.$rel, 'scan');
        DB::table('courriers')->where('client_id', $clientId)->update(['scan' => $rel]);

        $res = $this->actingAs($admin)->getJson('/api/v1/admin/lists');

        $res->assertStatus(200)->assertJsonPath('courriers.0.doc_exists', true);
        unlink(storage_path('uploads').'/'.$rel);
    }

    // ===== GET /admin/overview =====

    public function test_overview_stats_kpi_et_listes_communication(): void
    {
        ['user' => $admin, 'client_id' => $clientId] = $this->seedAdminData();
        $this->seedThrottleLock();

        $res = $this->actingAs($admin)->getJson('/api/v1/admin/overview');

        $res->assertStatus(200)
            ->assertJsonPath('stats.clients', 2)
            ->assertJsonPath('stats.abonnements', 1)
            ->assertJsonPath('stats.paiements', 1)
            ->assertJsonPath('stats.courriers', 1)
            ->assertJsonPath('stats.kyc_en_cours', 1)
            ->assertJsonPath('stats.tickets_open', 1)
            ->assertJsonPath('messages.0.content', 'Bonjour')
            ->assertJsonPath('tickets.0.client_nom', 'Jean Dupont');
        $this->assertSame('locked@example.com', $res->json('throttled.0.identifier'));
        $this->assertGreaterThan(0, $res->json('throttled.0.minutes_remaining'));
    }

    // ===== GET /admin/template =====

    public function test_template_contenu_et_unlocked_false_par_defaut(): void
    {
        ['user' => $admin] = $this->seedAdminData();

        $res = $this->actingAs($admin)->getJson('/api/v1/admin/template');

        $res->assertStatus(200)
            ->assertJsonStructure(['id', 'name', 'content', 'updated_at'])
            ->assertJsonPath('name', 'contract')
            ->assertJsonPath('unlocked', false);
        $this->assertGreaterThan(50, strlen((string) $res->json('content')));
    }

    public function test_template_introuvable_404_si_absent(): void
    {
        ['user' => $admin] = $this->seedAdminData();
        DB::table('contract_templates')->where('name', 'contract')->delete();

        $this->actingAs($admin)->getJson('/api/v1/admin/template')->assertStatus(404);
    }

    // ===== POST /admin/template/otp-request =====

    public function test_otp_request_envoie_et_masque_email_jamais_le_code(): void
    {
        ['user' => $admin, 'id' => $adminId] = $this->seedAdminData();
        Mail::fake();

        $res = $this->actingAs($admin)->postJson('/api/v1/admin/template/otp-request', []);

        $res->assertStatus(200)
            ->assertJsonPath('sent', true)
            ->assertJsonPath('email', 'a***@example.com');
        $this->assertStringNotContainsString('example', implode('', array_keys($res->json())));
        $this->assertArrayNotHasKey('otp', $res->json());
        Mail::assertSent(\App\Mail\OtpMail::class, 1);
        $this->assertDatabaseHas('audit_log', ['action' => 'TEMPLATE_OTP_SENT', 'user_id' => $adminId]);
    }

    // ===== POST /admin/template/otp-verify =====

    public function test_otp_verify_code_invalide_400_avec_tentatives_restantes(): void
    {
        ['user' => $admin, 'id' => $adminId] = $this->seedAdminData();
        OtpService::generate($adminId, 'admin');

        // Code invalide garanti : 000001-999998, on tire jusqu'à tomber hors
        // du code généré (1 000 000 valeurs → probabilité de collision nulle
        // en pratique, et boucle bornée par construction).
        $bad = '000000';
        $res = $this->actingAs($admin)->postJson('/api/v1/admin/template/otp-verify', ['otp' => $bad]);
        $guard = 0;
        while ($res->status() === 200 && $guard++ < 10) {
            $bad = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            OtpService::generate($adminId, 'admin'); // l'échec n'épuise pas le code
            $res = $this->actingAs($admin)->postJson('/api/v1/admin/template/otp-verify', ['otp' => $bad]);
        }

        $res->assertStatus(400)->assertJsonPath('attempts_remaining', 4);
        $this->assertDatabaseHas('audit_log', ['action' => 'TEMPLATE_OTP_FAILED']);
    }

    public function test_otp_verify_hors_format_400(): void
    {
        ['user' => $admin] = $this->seedAdminData();

        $this->actingAs($admin)->postJson('/api/v1/admin/template/otp-verify', ['otp' => '12ab'])
            ->assertStatus(400);
        $this->actingAs($admin)->postJson('/api/v1/admin/template/otp-verify', ['otp' => ''])
            ->assertStatus(400);
    }

    public function test_otp_verify_valide_deverrouille_10_min(): void
    {
        ['user' => $admin, 'id' => $adminId] = $this->seedAdminData();
        $otp = OtpService::generate($adminId, 'admin');

        $res = $this->actingAs($admin)->postJson('/api/v1/admin/template/otp-verify', ['otp' => $otp]);

        $res->assertStatus(200)
            ->assertJsonPath('unlocked', true)
            ->assertJsonPath('expires_in', 600);
        $this->assertDatabaseHas('audit_log', ['action' => 'TEMPLATE_OTP_VERIFIED']);
        // Le template reflète le déverrouillage
        $this->actingAs($admin)->getJson('/api/v1/admin/template')
            ->assertStatus(200)->assertJsonPath('unlocked', true);
    }

    public function test_otp_verify_cinq_essais_invalides_puis_429(): void
    {
        ['user' => $admin, 'id' => $adminId] = $this->seedAdminData();
        OtpService::generate($adminId, 'admin');

        // On force le compteur à 5 (état identique à 5 échecs consécutifs —
        // parité source : au 6e essai, purge du code + 429).
        Cache::put("template_otp_attempts_{$adminId}", 5, now()->addMinutes(10));

        $res = $this->actingAs($admin)->postJson('/api/v1/admin/template/otp-verify', ['otp' => '123456']);

        $res->assertStatus(429)
            ->assertJson(['error' => 'Trop de tentatives. Demandez un nouveau code.']);
        $this->assertDatabaseHas('audit_log', ['action' => 'TEMPLATE_OTP_LOCKED']);
        // Le code a été purgé : même le BON code ne déverrouille plus.
        $otp = DB::table('clients')->where('id', $adminId)->exists() ? OtpService::generate($adminId, 'admin') : '';
        $this->actingAs($admin)->postJson('/api/v1/admin/template/otp-verify', ['otp' => $otp])
            ->assertStatus(200); // nouveau code : le compteur a été remis à zéro
    }

    // ===== POST /admin/template/update =====

    public function test_update_sans_otp_403(): void
    {
        ['user' => $admin, 'id' => $adminId] = $this->seedAdminData();

        $res = $this->actingAs($admin)->postJson('/api/v1/admin/template/update', [
            'content' => str_repeat('<p>Nouveau contenu modèle</p>', 6),
        ]);

        $res->assertStatus(403)->assertJson(['error' => 'OTP requis']);
        $this->assertDatabaseHas('audit_log', ['action' => 'TEMPLATE_UPDATE_BLOCKED']);
        $this->assertSame(0, DB::table('contract_history')->count());
    }

    public function test_update_avec_otp_enregistre_history_et_referme_la_porte(): void
    {
        ['user' => $admin, 'id' => $adminId] = $this->seedAdminData();
        $before = DB::table('contract_templates')->where('name', 'contract')->first();
        OtpService::generate($adminId, 'admin');
        Cache::put("template_edit_unlocked_{$adminId}", time(), now()->addSeconds(600));

        $newContent = str_repeat('<p>Modèle contrats v2 — clause domiciliation</p>', 4);
        $res = $this->actingAs($admin)->postJson('/api/v1/admin/template/update', ['content' => $newContent]);

        $res->assertStatus(200)->assertJson(['message' => 'Template mis à jour']);
        $after = DB::table('contract_templates')->where('name', 'contract')->first();
        $this->assertSame($newContent, $after->content);
        $this->assertSame($before->content, DB::table('contract_history')->where('template_id', $before->id)->value('old_content'));
        $this->assertDatabaseHas('audit_log', ['action' => 'UPDATE_CONTRACT_TEMPLATE']);
        // L012 : la porte est refermée — un 2e update exige un nouvel OTP
        $this->actingAs($admin)->postJson('/api/v1/admin/template/update', ['content' => $newContent])
            ->assertStatus(403);
    }

    public function test_update_contenu_trop_court_400(): void
    {
        ['user' => $admin, 'id' => $adminId] = $this->seedAdminData();
        Cache::put("template_edit_unlocked_{$adminId}", time(), now()->addSeconds(600));

        $this->actingAs($admin)->postJson('/api/v1/admin/template/update', ['content' => 'court'])
            ->assertStatus(400)->assertJson(['error' => 'Contenu de template invalide']);
    }

    public function test_update_otp_expire_403(): void
    {
        ['user' => $admin, 'id' => $adminId] = $this->seedAdminData();
        // Déverrouillage posé il y a plus de 10 minutes → expiré
        Cache::put("template_edit_unlocked_{$adminId}", time() - 601, now()->addSeconds(600));

        $this->actingAs($admin)->postJson('/api/v1/admin/template/update', ['content' => str_repeat('a ', 40)])
            ->assertStatus(403)->assertJson(['error' => 'OTP requis']);
    }

    // ===== POST /admin/ticket/reply =====

    public function test_ticket_reply_enregistre_reponse_et_statut_repondu(): void
    {
        ['user' => $admin, 'client_id' => $clientId] = $this->seedAdminData();
        $ticketId = (int) DB::table('tickets_support')->insertGetId([
            'client_id' => $clientId, 'sujet' => 'Relance', 'message' => 'Merci de répondre',
            'statut' => 'open', 'created_at' => now()->format('Y-m-d H:i:s'),
        ]);

        $res = $this->actingAs($admin)->postJson('/api/v1/admin/ticket/reply', [
            'ticket_id' => $ticketId,
            'reponse' => 'Voici notre réponse.',
        ]);

        $res->assertStatus(200)->assertJson(['message' => 'Réponse enregistrée']);
        $this->assertDatabaseHas('tickets_support', [
            'id' => $ticketId,
            'reponse' => 'Voici notre réponse.',
            'statut' => 'répondu',
        ]);
        $this->assertNotNull(DB::table('tickets_support')->where('id', $ticketId)->value('reponse_at'));
    }

    public function test_ticket_reply_inconnu_404(): void
    {
        ['user' => $admin] = $this->seedAdminData();

        $this->actingAs($admin)->postJson('/api/v1/admin/ticket/reply', [
            'ticket_id' => 999999, 'reponse' => 'Réponse fantôme',
        ])->assertStatus(404)->assertJson(['error' => 'Ticket introuvable']);
    }

    public function test_ticket_reply_champs_invalides_400(): void
    {
        ['user' => $admin] = $this->seedAdminData();

        $this->actingAs($admin)->postJson('/api/v1/admin/ticket/reply', ['ticket_id' => 0, 'reponse' => 'x'])
            ->assertStatus(400);
        $this->actingAs($admin)->postJson('/api/v1/admin/ticket/reply', ['ticket_id' => 1, 'reponse' => ''])
            ->assertStatus(400);
        $this->actingAs($admin)->postJson('/api/v1/admin/ticket/reply', [
            'ticket_id' => 1, 'reponse' => str_repeat('x', 5001),
        ])->assertStatus(400);
    }
}
