<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;

/**
 * Parité de src/Service/PdfService.php : dompdf + Blade (templates Twig
 * convertis, HTML structurel IDENTIQUE — seul le moteur de template change).
 * - contrat : source de vérité = contract_templates (BDD, éditable admin),
 *   fallback fichier (comme ChainLoader source) ;
 * - facture : vue Blade système de fichiers ;
 * - Helvetica, A4 portrait, PHP distant désactivé ;
 * - fillDomicilie() : placeholders [DOMICILIE_*] inchangés.
 */
class PdfService
{
    /** @param  array<string,mixed>  $data */
    public function generateContract(array $data): string
    {
        $dompdf = $this->dompdf();

        $clientName = $data['client_name'] ?? 'Client';
        $companyName = $data['company_name'] ?? 'Entreprise';
        $price = (int) ($data['price'] ?? 50000);

        // Parité ChainLoader source : la BDD (éditable admin) prime ; sinon la
        // vue Blade convertie depuis contract.html.twig (HTML structurel identique).
        $dbContent = null;
        try {
            $tpl = DB::table('contract_templates')->where('name', 'contract')->first();
            if ($tpl && trim((string) $tpl->content) !== '') {
                $dbContent = (string) $tpl->content;
            }
        } catch (\Throwable $e) {
            $dbContent = null;
        }

        if ($dbContent !== null) {
            // Le contenu BDD contient les marqueurs Twig : substitution directe
            // (mêmes remplacements que SubscriptionController::preview() source).
            $html = str_replace(
                ['{{ client_name }}', "{{ company_name|default('Entreprise') }}", '{{ company_name }}', '{{ price }}'],
                [$clientName, $companyName, $companyName, number_format($price, 0, ',', ' ').' FCFA'],
                $dbContent
            );
        } else {
            $html = View::make('pdf.contract', [
                'client_name' => $clientName,
                'company_name' => $companyName,
                'price' => $price,
            ])->render();
        }

        // L022: substituer les placeholders [DOMICILIE_*] par les données réelles
        $html = self::fillDomicilie($html, $data);

        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /** @param  array<string,mixed>  $data */
    public function generateInvoice(array $data): string
    {
        $dompdf = $this->dompdf();

        $html = View::make('pdf.invoice', [
            'invoice_number' => $data['invoice_number'] ?? 'FAC-2026-001',
            'client_name' => $data['client_name'] ?? 'Client',
            'amount' => $data['amount'] ?? 50000,
        ])->render();

        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * L022 — Remplace les placeholders [DOMICILIE_*] du contrat par les
     * données réelles de la souscription (colonne abonnements.details +
     * champs directs). $data accepte 'details' (JSON string ou array).
     *
     * @param  array<string,mixed>  $data
     */
    public static function fillDomicilie(string $html, array $data): string
    {
        $d = $data['details'] ?? [];
        if (is_string($d)) {
            $d = json_decode($d, true) ?: [];
        }
        $get = function (string $key, string $fallback) use ($d, $data) {
            $v = $d[$key] ?? $data[$key] ?? '';
            $v = trim((string) $v);

            return $v !== '' ? $v : $fallback;
        };

        $rep = $get('representant', $data['client_name'] ?? '…………');
        $entreprise = $get('entreprise', $data['company_name'] ?? '…………');
        $today = now()->format('d/m/Y à H:i');

        $map = [
            '[DOMICILIE_RCCM]' => $get('rccm', "en cours d'immatriculation"),
            '[DOMICILIE_NIU]' => $get('niu', "en cours d'attribution"),
            '[DOMICILIE_SIEGE]' => $get('siege', '………'),
            '[DOMICILIE_REPRESENTANT]' => $rep,
            '[DOMICILIE_QUALITE]' => $get('qualite', 'Représentant légal'),
            '[DOMICILIE_ENTREPRISE]' => $entreprise,
            '[DOMICILIE_IP]' => request()?->ip() ?? '—',
            '[DOMICILIE_DATE]' => $today,
        ];

        return strtr($html, $map);
    }

    /**
     * Source de vérité du contrat : la BDD (éditable admin), fallback fichier
     * (parité ChainLoader Twig : ArrayLoader BDD + FilesystemLoader templates/).
     */
    public function contractTemplateContent(): string
    {
        try {
            $tpl = DB::table('contract_templates')->where('name', 'contract')->first();
            if ($tpl && trim((string) $tpl->content) !== '') {
                return (string) $tpl->content;
            }
        } catch (\Throwable $e) {
            // table absente : fallback fichier
        }

        return (string) file_get_contents(resource_path('views/pdf/contract-template.html'));
    }

    private function dompdf(): Dompdf
    {
        $options = new Options;
        $options->setDefaultFont('Helvetica');
        $options->setIsPhpEnabled(false);
        $options->setIsRemoteEnabled(false);

        return new Dompdf($options);
    }
}
