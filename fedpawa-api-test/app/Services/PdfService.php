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
    private array $unites = ['', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix',
        'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize', 'dix-sept', 'dix-huit', 'dix-neuf'];
    private array $dizaines = ['', '', 'vingt', 'trente', 'quarante', 'cinquante', 'soixante', 'soixante-dix', 'quatre-vingt', 'quatre-vingt-dix'];
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
        $variation = $data['variation'] ?? 2;
        $template = 'pdf.invoice_v' . $variation;
        if (! view()->exists($template)) {
            $template = 'pdf.invoice_v2';
        }

        $dompdf = $this->dompdf();

        $amount = (float) ($data['amount'] ?? 50000);

        // Logo de l'entreprise en base64 (data URI) pour embarquement dans le PDF
        $logoPath = '/root/FEDPAWA-CODEBASE/fedpawa-next/public/assets/images/logo.jpg';
        $logoDataUri = null;
        if (file_exists($logoPath)) {
            $logoDataUri = 'data:image/jpeg;base64,' . base64_encode(file_get_contents($logoPath));
        }

        $html = View::make($template, [
            'invoice_number' => $data['invoice_number'] ?? 'FAC-2026-001',
            'client_name' => $data['client_name'] ?? 'Client',
            'client_email' => $data['client_email'] ?? '',
            'client_phone' => $data['client_phone'] ?? '',
            'pack_label' => $data['pack_label'] ?? 'Pack BUSINESS — Domiciliation',
            'periode' => $data['periode'] ?? 'Semestriel (6 mois)',
            'designation' => $data['designation'] ?? 'Pack BUSINESS — Domiciliation (Semestriel (6 mois))',
            'amount' => $amount,
            'amount_en_lettres' => $this->amountEnLettres($amount),
            'logo_data_uri' => $logoDataUri,
        ])->render();

        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Convertit un montant entier en lettres (français), ex. 270000 -> "Deux cent soixante-dix mille".
     * Gère les montants jusqu'à 999 999 999.
     */
    private function amountEnLettres(float $montant): string
    {
        $n = (int) round($montant);
        if ($n === 0) {
            return 'Zéro';
        }

        $m = (int) ($n / 1000000);
        $r = $n % 1000000;
        $mille = (int) ($r / 1000);
        $reste = $r % 1000;

        $parts = [];
        if ($m > 0) {
            $parts[] = ($m > 1 ? $this->sousMille($m) . ' ' : '') . 'million' . ($m > 1 ? 's' : '');
        }
        if ($mille > 0) {
            $parts[] = ($mille > 1 ? $this->sousMille($mille) . ' ' : '') . 'mille';
        }
        if ($reste > 0) {
            $parts[] = $this->sousMille($reste);
        }

        $result = implode(' ', $parts);
        return ucfirst($result);
    }

    /**
     * Convertit un nombre de 0 à 999 en lettres (français).
     */
    private function sousMille(int $n): string
    {
        $res = '';
        $c = (int) ($n / 100);
        $r = $n % 100;
        if ($c > 0) {
            $res .= ($c > 1 ? $this->unites[$c] . ' ' : '') . 'cent';
            if ($r > 0) {
                $res .= ' ';
            } else {
                $res .= $c > 1 ? 's' : '';
            }
        }
        if ($r >= 20) {
            $d = (int) ($r / 10);
            $u = $r % 10;
            if ($d === 7 || $d === 9) {
                $d = ($d === 7) ? 6 : 8;
                $u += 10;
            }
            $res .= $this->dizaines[$d];
            if ($u === 1 && $d === 8) {
                $res .= '-et-un';
            } elseif ($u > 0) {
                $res .= ($d === 8 ? '-' : '-') . $this->unites[$u];
                if ($d === 8 && $u === 0) {
                    $res .= 's';
                }
            }
        } elseif ($r > 0) {
            $res .= $this->unites[$r];
        }
        return trim($res);
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
