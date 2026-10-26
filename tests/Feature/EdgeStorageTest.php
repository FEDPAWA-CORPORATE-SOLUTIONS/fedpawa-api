<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\BootstrapsBusinessSchema;
use Tests\Support\BootstrapsEdgeSchema;
use Tests\TestCase;

/**
 * Parité StorageController : GET /api/v1/document + /api/v1/admin/document-info.
 * - ownership : client ne voit QUE ses documents (403 sinon) ;
 * - anti path-traversal (403) ; - headers PDF inline ;
 * - document-info admin : exists/size/mime/date/download_url.
 */
class EdgeStorageTest extends TestCase
{
    use BootstrapsBusinessSchema, BootstrapsEdgeSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapBusinessSchema();
        $this->bootstrapEdgeSchema();
    }

    private function seedCourrier(int $clientId, string $scan): int
    {
        return (int) DB::table('courriers')->insertGetId([
            'client_id' => $clientId,
            'date_reception' => now()->format('Y-m-d H:i:s'),
            'scan' => $scan,
            'statut' => 'non_lu',
        ]);
    }

    private function putScan(string $rel, string $content = '%PDF-1.4 test'): void
    {
        @mkdir(dirname(storage_path('app/private/uploads/'.$rel)), 0755, true);
        file_put_contents(storage_path('app/private/uploads/'.$rel), $content);
    }

    public function test_document_sans_auth_401(): void
    {
        $this->getJson('/api/v1/document', ['type' => 'courrier', 'id' => 1])->assertStatus(401);
    }

    public function test_document_serve_pdf_au_proprietaire(): void
    {
        ['user' => $user, 'id' => $id] = $this->businessClient('doc-owner@example.com');
        $this->putScan("client_{$id}/scan.pdf");
        $courrierId = $this->seedCourrier($id, "client_{$id}/scan.pdf");

        $res = $this->actingAs($user)->getJson("/api/v1/document?type=courrier&id={$courrierId}");

        // StreamedResponse : le contenu passe par output buffering en test ;
        // assertOk vérifie statut + headers PDF inline.
        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('inline; filename="courrier_'.$courrierId.'.pdf"', (string) $res->headers->get('Content-Disposition'));
        $this->assertDatabaseHas('audit_log', ['action' => 'DOCUMENT_VIEWED']);
    }

    public function test_document_autre_client_403(): void
    {
        ['user' => $a] = $this->businessClient('doc-a@example.com');
        ['id' => $b] = $this->businessClient('doc-b@example.com');
        $this->putScan("client_{$b}/secret.pdf");
        $courrierId = $this->seedCourrier($b, "client_{$b}/secret.pdf");

        $this->actingAs($a)->getJson("/api/v1/document?type=courrier&id={$courrierId}")
            ->assertStatus(403)
            ->assertJson(['error' => 'Non autorisé']);
    }

    public function test_document_path_traversal_refuse(): void
    {
        ['user' => $user, 'id' => $id] = $this->businessClient('doc-traversal@example.com');
        $courrierId = $this->seedCourrier($id, '../../../etc/passwd');

        $this->actingAs($user)->getJson("/api/v1/document?type=courrier&id={$courrierId}")
            ->assertStatus(403)
            ->assertJson(['error' => 'Accès au fichier refusé']);
    }

    public function test_document_type_invalide_400(): void
    {
        ['user' => $user] = $this->businessClient('doc-type@example.com');
        $this->actingAs($user)->getJson('/api/v1/document?type=banque&id=1')
            ->assertStatus(400)
            ->assertJson(['error' => 'Type de document invalide']);
    }

    public function test_document_info_admin_existe(): void
    {
        ['user' => $admin] = $this->businessClient('admin-doc@example.com', 'admin');
        ['id' => $cible] = $this->businessClient('cible-doc@example.com');
        $this->putScan("client_{$cible}/info.pdf", '%PDF-1.4 info');
        $courrierId = $this->seedCourrier($cible, "client_{$cible}/info.pdf");

        $res = $this->actingAs($admin)->getJson("/api/v1/admin/document-info?type=courrier&id={$courrierId}");

        $res->assertStatus(200)
            ->assertJsonPath('exists', true)
            ->assertJsonPath('filename', 'info.pdf')
            ->assertJsonPath('client_nom', 'Jean Dupont')
            ->assertJsonPath('type_label', 'Courrier (scan)')
            ->assertJsonPath('download_url', "/api/v1/document?type=courrier&id={$courrierId}");
        $this->assertGreaterThan(0, $res->json('size'));
    }

    public function test_document_info_client_403(): void
    {
        ['user' => $user] = $this->businessClient('doc-info-client@example.com');
        $this->actingAs($user)->getJson('/api/v1/admin/document-info?type=courrier&id=1')
            ->assertStatus(403);
    }

    public function test_document_info_kyc_fichier_invalide_400(): void
    {
        ['user' => $admin] = $this->businessClient('admin-kyc@example.com', 'admin');
        $this->actingAs($admin)->getJson('/api/v1/admin/document-info?type=kyc&id=1&file=passport')
            ->assertStatus(400)
            ->assertJson(['error' => 'Fichier invalide']);
    }
}
