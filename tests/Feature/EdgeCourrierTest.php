<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\BootstrapsBusinessSchema;
use Tests\Support\BootstrapsEdgeSchema;
use Tests\TestCase;

/**
 * Parité CourrierController + CourierController :
 * - GET /api/v1/courriers : 401 sans auth, listing du client (DESC) ;
 * - POST /api/v1/admin/courrier/add : admin (403 client), insert non_lu ;
 * - POST /api/v1/courrier/upload : PDF réel -> fusion -> réponse
 *   {'message': 'Upload et conversion multi-pages réussis', 'path': ...}.
 */
class EdgeCourrierTest extends TestCase
{
    use BootstrapsBusinessSchema, BootstrapsEdgeSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapBusinessSchema();
        $this->bootstrapEdgeSchema();
    }

    private function pdfFile(string $name = 'scan.pdf'): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pdf').'.pdf';
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml('<p>Page test</p>');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        file_put_contents($tmp, $dompdf->output());

        return $tmp;
    }

    public function test_courriers_sans_auth_401(): void
    {
        $this->getJson('/api/v1/courriers')->assertStatus(401);
    }

    public function test_courriers_listing_desc_par_client(): void
    {
        ['user' => $user, 'id' => $id] = $this->businessClient('courrier-a@example.com');
        ['id' => $other] = $this->businessClient('courrier-b@example.com');
        $now = now()->format('Y-m-d H:i:s');

        DB::table('courriers')->insert([
            ['client_id' => $id, 'date_reception' => '2026-01-01 10:00:00', 'scan' => 'client_1/a.pdf', 'statut' => 'non_lu'],
            ['client_id' => $id, 'date_reception' => '2026-02-01 10:00:00', 'scan' => 'client_1/b.pdf', 'statut' => 'non_lu'],
            ['client_id' => $other, 'date_reception' => '2026-03-01 10:00:00', 'scan' => 'client_2/c.pdf', 'statut' => 'non_lu'],
        ]);

        $res = $this->actingAs($user)->getJson('/api/v1/courriers');
        $res->assertStatus(200);
        $rows = $res->json('data');
        $this->assertSame(2, count($rows));
        $this->assertSame('2026-02-01 10:00:00', $rows[0]['date_reception']); // DESC
        $this->assertSame($id, $rows[0]['client_id']);
    }

    public function test_admin_courrier_add(): void
    {
        ['user' => $admin] = $this->businessClient('admin-courrier@example.com', 'admin');
        ['id' => $cible] = $this->businessClient('cible-courrier@example.com');

        // Client simple : 403 (garde CheckAdminRole)
        ['user' => $clientUser] = $this->businessClient('client-courrier@example.com');
        $this->actingAs($clientUser)->postJson('/api/v1/admin/courrier/add', [
            'client_id' => $cible, 'scan_path' => 'client_1/scan.pdf',
        ])->assertStatus(403);

        // Admin : 200 + insertion
        $this->actingAs($admin)->postJson('/api/v1/admin/courrier/add', [
            'client_id' => $cible, 'scan_path' => 'client_1/scan.pdf',
        ])->assertStatus(200)->assertJson(['message' => 'Courrier ajouté']);

        $this->assertDatabaseHas('courriers', [
            'client_id' => $cible,
            'scan' => 'client_1/scan.pdf',
            'statut' => 'non_lu',
        ]);
    }

    public function test_admin_courrier_add_validation_422(): void
    {
        ['user' => $admin] = $this->businessClient('admin-courrier2@example.com', 'admin');
        $this->actingAs($admin)->postJson('/api/v1/admin/courrier/add', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['client_id', 'scan_path']);
    }

    public function test_courier_upload_pdf_reel(): void
    {
        ['user' => $user, 'id' => $id] = $this->businessClient('courier-up@example.com');
        $pdf = $this->pdfFile();

        $res = $this->actingAs($user)->post('/api/v1/courrier/upload', [
            'documents' => [new \Illuminate\Http\UploadedFile($pdf, 'scan.pdf', 'application/pdf', null, true)],
        ]);

        $res->assertStatus(200)->assertJson(['message' => 'Upload et conversion multi-pages réussis']);
        $this->assertMatchesRegularExpression('#^client_'.$id.'/doc_[a-f0-9]+\.pdf$#', $res->json('path'));

        // Le PDF final existe sous storage/app/private/uploads/
        $this->assertFileExists(storage_path('app/private/uploads/'.$res->json('path')));
        // Ligne courriers créée
        $this->assertDatabaseHas('courriers', ['client_id' => $id, 'scan' => $res->json('path')]);

        unlink($pdf);
    }

    public function test_courier_upload_aucun_fichier_400(): void
    {
        ['user' => $user] = $this->businessClient('courrier-empty@example.com');
        $this->actingAs($user)->postJson('/api/v1/courrier/upload', [])
            ->assertStatus(400)
            ->assertJson(['error' => 'Aucun fichier']);
    }
}
