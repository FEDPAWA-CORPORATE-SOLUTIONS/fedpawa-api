<?php

namespace Tests\Feature;

use App\Services\FactureService;
use App\Services\PdfService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BootstrapsBusinessSchema;
use Tests\TestCase;

/**
 * Facturation PDF : dompdf + Blade converti depuis Twig (rendu identique).
 * - facture créée au paiement reçu (W-10), PDF stocké, lien_pdf rempli ;
 * - contenu du PDF asserté (numéro, client, montant formaté FCFA) ;
 * - GET /api/factures (liste) + /api/factures/{id}/pdf (download) ;
 * - contrat PDF : placeholders [DOMICILIE_*] remplis.
 */
class InvoicePdfTest extends TestCase
{
    use BootstrapsBusinessSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapBusinessSchema();
        Storage::fake('local');
    }

    public function test_facture_pdf_generee_avec_contenu_attendu(): void
    {
        ['id' => $clientId, 'user' => $user] = $this->businessClient('fac1@example.com');
        DB::table('paiements')->insert([
            'client_id' => $clientId, 'montant' => 25000, 'mode_paiement' => 'campay',
            'transaction_id' => 'TX-FAC-1', 'date' => now()->format('Y-m-d H:i:s'), 'statut' => 'recu',
        ]);

        $factureId = FactureService::createForPayment($clientId, 25000.0, 'TX-FAC-1');
        $this->assertNotNull($factureId);

        $facture = DB::table('factures')->where('id', $factureId)->first();
        $this->assertSame('FAC-TX-FAC-1', $facture->numero);
        $this->assertSame('documents/facture_'.$factureId.'.pdf', $facture->lien_pdf);

        // PDF réellement stocké et lisible
        Storage::disk('local')->assertExists('documents/facture_'.$factureId.'.pdf');
        $bin = Storage::disk('local')->get('documents/facture_'.$factureId.'.pdf');
        $this->assertStringStartsWith('%PDF', $bin);

        // extraction texte (pdftotext si dispo, sinon assertion binaire minimale)
        $tmp = tempnam(sys_get_temp_dir(), 'fac').'.pdf';
        file_put_contents($tmp, $bin);
        $out = @shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>/dev/null');
        if ($out !== null && trim($out) !== '') {
            $this->assertStringContainsString('FACTURE', $out);
            $this->assertStringContainsString('FAC-TX-FAC-1', $out);
            $this->assertStringContainsString('Jean Dupont', $out);
            $this->assertStringContainsString('25 000', $out); // montant formaté FR
            $this->assertStringContainsString('FEDPAWA CORPORATE SOLUTIONS', $out);
        } else {
            $this->assertGreaterThan(1000, strlen($bin)); // PDF substantiel généré
        }
        unlink($tmp);
    }

    public function test_facture_idempotente_par_transaction(): void
    {
        ['id' => $clientId] = $this->businessClient('fac2@example.com');
        $id1 = FactureService::createForPayment($clientId, 1000.0, 'TX-IDEM');
        $id2 = FactureService::createForPayment($clientId, 1000.0, 'TX-IDEM');
        $this->assertNotNull($id1);
        $this->assertNull($id2); // 2e appel : rien à faire (parité idempotence)
        $this->assertSame(1, DB::table('factures')->where('numero', 'FAC-TX-IDEM')->count());
    }

    public function test_liste_factures_du_client_connecte(): void
    {
        ['id' => $clientId, 'user' => $user] = $this->businessClient('fac3@example.com');
        DB::table('factures')->insert([
            'client_id' => $clientId, 'numero' => 'FAC-LIST-1', 'montant' => 5000,
            'date' => now()->format('Y-m-d H:i:s'), 'lien_pdf' => null,
        ]);
        // facture d'un autre client : invisible
        $other = $this->businessClient('other-fac@example.com');
        DB::table('factures')->insert([
            'client_id' => $other['id'], 'numero' => 'FAC-OTHER-1', 'montant' => 999,
            'date' => now()->format('Y-m-d H:i:s'), 'lien_pdf' => null,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/factures');
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('FAC-LIST-1', $data[0]['numero']);
        $this->assertEquals(5000.0, $data[0]['montant']);
    }

    public function test_download_pdf_facture(): void
    {
        ['id' => $clientId, 'user' => $user] = $this->businessClient('fac4@example.com');
        $pdf = (new PdfService)->generateInvoice([
            'invoice_number' => 'FAC-DL-1', 'client_name' => 'Jean Dupont', 'amount' => 12500,
        ]);
        Storage::disk('local')->put('documents/facture_dl.pdf', $pdf);
        $factureId = (int) DB::table('factures')->insertGetId([
            'client_id' => $clientId, 'numero' => 'FAC-DL-1', 'montant' => 12500,
            'date' => now()->format('Y-m-d H:i:s'), 'lien_pdf' => 'documents/facture_dl.pdf',
        ]);

        $response = $this->actingAs($user)->get('/api/v1/factures/'.$factureId.'/pdf');
        $response->assertStatus(200);
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_contrat_pdf_avec_placeholders_domicilie_remplis(): void
    {
        $pdf = (new PdfService)->generateContract([
            'client_name' => 'Jean Dupont',
            'company_name' => 'FEDPAWA TEST SARL',
            'price' => 45000,
            'details' => json_encode([
                'representant' => 'Jean Dupont', 'qualite' => 'Gérant',
                'entreprise' => 'FEDPAWA TEST SARL', 'rccm' => 'RCCM/DL/2024/B/1234',
                'niu' => 'M123456789', 'capital' => '1000000', 'siege' => 'Douala Logpom',
            ]),
        ]);
        $this->assertStringStartsWith('%PDF', $pdf);

        $tmp = tempnam(sys_get_temp_dir(), 'ctr').'.pdf';
        file_put_contents($tmp, $pdf);
        $out = @shell_exec('pdftotext '.escapeshellarg($tmp).' - 2>/dev/null');
        if ($out !== null && trim($out) !== '') {
            // le contrat contient le nom du client et la convention (HTML structurel conservé)
            $this->assertStringContainsString('Jean Dupont', $out);
            $this->assertStringContainsString('CONVENTION', $out);
            // placeholders remplacés par les données réelles
            $this->assertStringNotContainsString('[DOMICILIE_REPRESENTANT]', $out);
            $this->assertStringNotContainsString('[DOMICILIE_RCCM]', $out);
        } else {
            $this->assertGreaterThan(20000, strlen($pdf)); // contrat long (17k HTML) rendu
        }
        unlink($tmp);
    }

    public function test_vue_blade_invoice_rend_identiquement_le_twig(): void
    {
        // Rendu Blade : mêmes valeurs substituées que le Twig source
        $html = view('pdf.invoice', [
            'invoice_number' => 'FAC-CMP-1', 'client_name' => 'Jean Dupont', 'amount' => 25000,
        ])->render();
        $this->assertStringContainsString('FAC-CMP-1', $html);
        $this->assertStringContainsString('Jean Dupont', $html);
        $this->assertStringContainsString('25 000', $html); // number_format(0,',',' ') — parité Twig
        $this->assertStringContainsString('FACTURE / REÇU DE PAIEMENT', $html);
        $this->assertStringContainsString('M09261890362B', $html); // NIU inchangé
        $this->assertStringContainsString('CM-DLA-01-2026-B13-00767', $html); // RCCM inchangé

        // Structure HTML identique : le fichier Blade est le Twig avec SEULES
        // les balises de substitution converties ({{ x|default(n)|fmt }} → Blade).
        $twig = file_get_contents('/root/Fedpawa/fedpawa_light/templates/invoice.html.twig');
        $blade = file_get_contents(resource_path('views/pdf/invoice.blade.php'));
        $normalize = function (string $s): array {
            // masque les expressions template (twig ou blade) par {{X}}
            $s = preg_replace('/\{\{[^}]*\}\}/', '{{X}}', $s);

            return explode("\n", trim((string) $s));
        };
        $this->assertSame($normalize($twig), $normalize($blade));
    }
}
