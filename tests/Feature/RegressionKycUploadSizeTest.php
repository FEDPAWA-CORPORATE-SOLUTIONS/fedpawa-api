<?php

namespace Tests\Feature;

use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BootstrapsBusinessSchema;
use Tests\TestCase;

/**
 * Régression KYC (test-supervisors) : un upload 12 Mo renvoyait un 413 brut
 * du serveur (post_max_size < body) AVANT le message métier « max 10 Mo ».
 * Le FormRequest doit valider max:10240 (422 métier) dès que la requête
 * atteint Laravel ; le front annonce désormais 10 Mo (parité FileUploader).
 */
class RegressionKycUploadSizeTest extends TestCase
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
        return File::createWithContent('identite.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
    }

    private function validPngOfSize(int $bytes): File
    {
        // PNG minimal avec IHDR valide (sinon finfo voit application/octet-stream)
        $ihdrFields = pack('NNCCCCC', 1, 1, 8, 6, 0, 0, 0); // w,h,depth,color,comp,filter,interlace
        $ihdr = 'IHDR'.$ihdrFields.pack('N', crc32('IHDR'.$ihdrFields));
        $body = "\x89PNG\r\n\x1a\n".pack('N', 13).$ihdr;

        return File::createWithContent('doc.png', $body.str_repeat('A', max(0, $bytes - strlen($body))));
    }

    public function test_upload_12mo_rejete_422_message_metier(): void
    {
        ['id' => $clientId, 'user' => $user] = $this->businessClient('kyc-12mo@example.com');

        $big = $this->validPngOfSize(12 * 1024 * 1024);

        $res = $this->actingAs($user)->post('/api/v1/kyc/submit', [
            'piece_identite' => $big,
            'justificatif_domicile' => $this->validPdf(),
        ]);

        // message métier (pas 413/500) : « Fichier trop volumineux (max 10 Mo). »
        $res->assertStatus(422);
        $this->assertSame(
            'Fichier trop volumineux (max 10 Mo).',
            $res->json('errors.piece_identite.0')
        );

        // aucune ligne kyc insérée, aucun fichier persisté
        $this->assertSame(0, (int) DB::table('kyc')->where('client_id', $clientId)->count());
        $this->assertFalse(Storage::disk('local')->exists('uploads/client_'.$clientId.'/big.png'));
    }

    public function test_upload_9mo_sous_la_limite_passe(): void
    {
        ['id' => $clientId, 'user' => $user] = $this->businessClient('kyc-9mo@example.com');

        $ok = $this->validPngOfSize(9 * 1024 * 1024);

        $res = $this->actingAs($user)->post('/api/v1/kyc/submit', [
            'piece_identite' => $ok,
            'justificatif_domicile' => $this->validPdf(),
        ]);

        $res->assertStatus(200)->assertJsonPath('message', 'Dossier KYC soumis');
        $this->assertSame(1, (int) DB::table('kyc')->where('client_id', $clientId)->count());
    }
}
