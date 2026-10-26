<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\BootstrapsBusinessSchema;
use Tests\Support\BootstrapsEdgeSchema;
use Tests\TestCase;

/**
 * Parité InternalMailController : /api/v1/mail/{send,reply,thread}.
 * - send : client -> admin, audit MAIL_SENT ; - reply : admin only
 *   (403 client), 404 client inconnu ; - thread : fil + is_read=1 (L020).
 */
class EdgeMailTest extends TestCase
{
    use BootstrapsBusinessSchema, BootstrapsEdgeSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapBusinessSchema();
        $this->bootstrapEdgeSchema();
    }

    public function test_mail_routes_sans_auth_401(): void
    {
        $this->getJson('/api/v1/mail/thread')->assertStatus(401);
        $this->postJson('/api/v1/mail/send', ['content' => 'x'])->assertStatus(401);
    }

    public function test_send_client_vers_admin_200(): void
    {
        ['user' => $user, 'id' => $id] = $this->businessClient('mail-client@example.com');

        $res = $this->actingAs($user)->postJson('/api/v1/mail/send', ['content' => 'Bonjour admin']);

        $res->assertStatus(200)->assertJson(['message' => 'Message envoyé']);
        $this->assertDatabaseHas('internal_messages', [
            'sender_id' => $id,
            'sender_type' => 'client',
            'receiver_id' => null,
            'receiver_type' => 'admin',
            'content' => 'Bonjour admin',
        ]);
        $this->assertDatabaseHas('audit_log', ['action' => 'MAIL_SENT', 'user_type' => 'client']);
    }

    public function test_send_contenu_vide_422(): void
    {
        ['user' => $user] = $this->businessClient('mail-empty@example.com');
        $this->actingAs($user)->postJson('/api/v1/mail/send', ['content' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['content']);
    }

    public function test_reply_par_client_403(): void
    {
        ['user' => $user] = $this->businessClient('mail-reply-client@example.com');
        $this->actingAs($user)->postJson('/api/v1/mail/reply', ['client_id' => 1, 'content' => 'x'])
            ->assertStatus(403);
    }

    public function test_reply_par_admin_200_et_404_si_client_inconnu(): void
    {
        ['user' => $admin, 'id' => $adminId] = $this->businessClient('admin-fedpawa@example.com', 'admin');
        ['id' => $clientId] = $this->businessClient('mail-cible@example.com');

        $res = $this->actingAs($admin)->postJson('/api/v1/mail/reply', [
            'client_id' => $clientId,
            'content' => 'Réponse du support',
        ]);
        $res->assertStatus(200)->assertJson(['message' => 'Réponse envoyée']);
        $this->assertDatabaseHas('internal_messages', [
            'sender_id' => $adminId,
            'sender_type' => 'admin',
            'receiver_id' => $clientId,
            'receiver_type' => 'client',
        ]);

        $this->actingAs($admin)->postJson('/api/v1/mail/reply', [
            'client_id' => 999999,
            'content' => 'x',
        ])->assertStatus(404)->assertJson(['error' => 'Client introuvable']);
    }

    public function test_thread_marque_messages_lus_L020(): void
    {
        ['user' => $user, 'id' => $id] = $this->businessClient('mail-thread@example.com');
        $now = now()->format('Y-m-d H:i:s');

        DB::table('internal_messages')->insert([
            ['sender_id' => $id, 'sender_type' => 'client', 'receiver_id' => null, 'receiver_type' => 'admin', 'content' => 'Q1', 'is_read' => 0, 'created_at' => $now],
            ['sender_id' => null, 'sender_type' => 'admin', 'receiver_id' => $id, 'receiver_type' => 'client', 'content' => 'R1', 'is_read' => 0, 'created_at' => $now],
        ]);

        $res = $this->actingAs($user)->getJson('/api/v1/mail/thread');
        $res->assertStatus(200);
        $this->assertSame(2, count($res->json('data')));

        // L020 : le message reçu par le client est passé à is_read=1
        $this->assertDatabaseHas('internal_messages', ['receiver_id' => $id, 'receiver_type' => 'client', 'content' => 'R1', 'is_read' => 1]);
    }
}
