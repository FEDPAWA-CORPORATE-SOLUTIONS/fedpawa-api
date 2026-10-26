<?php

namespace Tests\Feature;

use App\Services\KycService;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BootstrapsBusinessSchema;
use Tests\TestCase;

/**
 * KYC upload ok/ko : parité KycService (MIME réel finfo, PDF/JPEG/PNG only,
 * 10 Mo max, dossier client_<id>, statut en_cours) + garde admin approve/reject.
 */
class KycUploadTest extends TestCase
{
    use BootstrapsBusinessSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapBusinessSchema();
        Storage::fake('local');
    }

    private function validPdf(): File
    {
        // vrai contenu PDF minimal (%PDF magic) — le finfo lit le contenu réel
        return File::createWithContent('identite.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
    }

    public function test_upload_ok_deux_pieces(): void
    {
        ['id' => $clientId, 'user' => $user] = $this->businessClient('kyc1@example.com');

        $response = $this->actingAs($user)->post('/api/v1/kyc/submit', [
            'piece_identite' => $this->validPdf(),
            'justificatif_domicile' => $this->validPdf(),
        ]);

        $response->assertStatus(200)->assertJsonPath('message', 'Dossier KYC soumis');

        $kyc = DB::table('kyc')->where('client_id', $clientId)->first();
        $this->assertNotNull($kyc);
        $this->assertSame('en_cours', $kyc->statuts);
        $this->assertStringStartsWith('client_'.$clientId.'/doc_', (string) $kyc->piece_identite);
        $this->assertStringStartsWith('client_'.$clientId.'/doc_', (string) $kyc->justificatif_domicile);

        // fichiers réellement stockés (Storage facade, disque local)
        Storage::disk('local')->assertExists('uploads/'.$kyc->piece_identite);
        Storage::disk('local')->assertExists('uploads/'.$kyc->justificatif_domicile);
    }

    public function test_upload_ko_format_non_autorise(): void
    {
        ['user' => $user] = $this->businessClient('kyc2@example.com');
        // un .pdf renommé mais contenu texte -> extension vérifiée par FormRequest
        $this->actingAs($user)->post('/api/v1/kyc/submit', [
            'piece_identite' => File::createWithContent('identite.txt', 'not a pdf'),
            'justificatif_domicile' => $this->validPdf(),
        ])->assertStatus(422); // gate FormRequest (mimes) — parité 'Format de fichier non autorisé.'
    }

    public function test_upload_ko_fichier_manquant(): void
    {
        ['user' => $user] = $this->businessClient('kyc3@example.com');
        $this->actingAs($user)->post('/api/v1/kyc/submit', [
            'piece_identite' => $this->validPdf(),
        ])->assertStatus(422); // parité 'Pièce d'identité et justificatif de domicile requis'
    }

    public function test_upload_ko_mime_reel_non_autorise_via_service(): void
    {
        // Bypass FormRequest : appel direct du service — le finfo lit le contenu
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Format de fichier non autorisé.');
        ['id' => $clientId] = ['id' => 0];
        ['id' => $clientId, 'user' => $user] = $this->businessClient('kyc4@example.com');
        $fake = File::createWithContent('fake.pdf', 'ceci est du texte, pas un PDF');
        app(KycService::class)->submit($clientId, [
            'piece_identite' => $fake,
            'justificatif_domicile' => $fake,
        ]);
    }

    public function test_upload_ko_trop_volumineux(): void
    {
        ['user' => $user] = $this->businessClient('kyc5@example.com');
        // PNG réel de >10 Mo (padding après IEND, pas de GD requise)
        $png = "\x89PNG\r\n\x1a\n".str_repeat('A', 11 * 1024 * 1024);
        $big = File::createWithContent('big.png', $png);
        $this->actingAs($user)->post('/api/v1/kyc/submit', [
            'piece_identite' => $big,
            'justificatif_domicile' => $this->validPdf(),
        ])->assertStatus(422); // gate max 10 Mo (FormRequest)
    }

    public function test_upload_anonyme_refuse(): void
    {
        $this->postJson('/api/v1/kyc/submit', [
            'piece_identite' => $this->validPdf(),
            'justificatif_domicile' => $this->validPdf(),
        ], ['Accept' => 'application/json'])->assertStatus(401);
    }

    public function test_admin_approve_et_reject(): void
    {
        ['id' => $clientId, 'user' => $user] = $this->businessClient('kyc6@example.com');
        $admin = $this->businessClient('admin@example.com', 'admin');

        $kycId = (int) DB::table('kyc')->insertGetId([
            'client_id' => $clientId, 'statuts' => 'en_cours',
            'piece_identite' => 'a.pdf', 'justificatif_domicile' => 'b.pdf',
            'date_soumission' => now()->format('Y-m-d H:i:s'),
        ]);

        // client lambda : 403 (parité 'Accès administrateur requis')
        $this->actingAs($user)->postJson('/api/v1/admin/kyc/approve', ['id' => $kycId])
            ->assertStatus(403);

        // admin : approve puis reject d'un second dossier
        $this->actingAs($admin['user'])->postJson('/api/v1/admin/kyc/approve', ['id' => $kycId])
            ->assertStatus(200)->assertJsonPath('message', 'Dossier KYC approuvé');
        $this->assertDatabaseHas('kyc', ['id' => $kycId, 'statuts' => 'approuvé']);

        $this->actingAs($admin['user'])->postJson('/api/v1/admin/kyc/reject', ['id' => $kycId])
            ->assertStatus(200)->assertJsonPath('message', 'Dossier KYC rejeté');
        $this->assertDatabaseHas('kyc', ['id' => $kycId, 'statuts' => 'rejeté']);

        // id inconnu : 404 (parité rowCount===0)
        $this->actingAs($admin['user'])->postJson('/api/v1/admin/kyc/approve', ['id' => 999999])
            ->assertStatus(404)->assertJsonPath('error', 'Dossier KYC introuvable');
    }
}
