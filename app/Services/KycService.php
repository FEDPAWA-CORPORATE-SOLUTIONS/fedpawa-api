<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\EventMail;

/**
 * Parité de src/Service/KycService.php : upload des deux pièces via
 * FileUploader (MIME réel, 10 Mo max), insertion kyc statut 'en_cours',
 * audit KYC_SUBMITTED. Transaction : aucun fichier persisté si échec.
 *
 * Un e-mail est également envoyé à l'administrateur à chaque nouveau dépôt
 * (best-effort, jamais bloquant).
 */
class KycService
{
    /** @param  array<string,UploadedFile>  $files */
    public function submit(int $clientId, array $files): bool
    {
        if (empty($files['piece_identite']) || empty($files['justificatif_domicile'])) {
            throw new \Exception("Pièce d'identité et justificatif de domicile requis.");
        }

        $uploader = new FileUploader;

        return DB::transaction(function () use ($clientId, $files, $uploader) {
            $paths = [];
            foreach (['piece_identite', 'justificatif_domicile'] as $key) {
                $paths[$key] = $uploader->upload($files[$key], 'client_'.$clientId);
            }

            $kycId = DB::table('kyc')->insertGetId([
                'client_id' => $clientId,
                'piece_identite' => $paths['piece_identite'],
                'justificatif_domicile' => $paths['justificatif_domicile'],
                'statuts' => 'en_cours',
                'date_soumission' => now()->format('Y-m-d H:i:s'),
            ]);

            AuditLogger::log('KYC_SUBMITTED', $clientId, 'client', 'Files: '.implode(', ', $paths).' | KYC ID: '.$kycId);

            // E-mail à l'administrateur : nouveau dossier KYC à examiner (best-effort).
            $this->notifierAdminNouveauKyc($clientId, (int) $kycId);

            return true;
        });
    }

    /**
     * Alerte l'administrateur qu'un dossier KYC vient d'être soumis.
     * Best-effort : un échec d'e-mail ne fait pas échouer la soumission.
     *
     * @return void
     */
    private function notifierAdminNouveauKyc(int $clientId, int $kycId): void
    {
        $client = DB::table('clients')->where('id', $clientId)->first();
        if (! $client) {
            return;
        }
        $adminEmail = (string) config('mail.from.address', 'contact@fedpawa.com');
        if (! $adminEmail || ! filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $dateMaj = now()->format('d/m/Y');
        $lien = config('app.url', 'https://www.fedpawa.com').'/admin-dashboard?tab=kyc';
        try {
            Mail::to($adminEmail)->send(new EventMail(
                'kyc_submit_'.$kycId,
                'Nouveau dossier KYC soumis — FEDPAWA',
                'emails.15-alerte-admin-nouveau-kyc',
                [
                    'client_nom' => $client->nom ?? 'Client',
                    'client_email' => $client->email,
                    'annee' => now()->year,
                    'kyc_id' => $kycId,
                    'date_maj' => $dateMaj,
                    'lien_action' => $lien,
                ]
            ));
        } catch (\Throwable $e) {
            \Log::warning('E-mail alerte admin nouveau KYC non envoyé : '.$e->getMessage());
        }
    }
}
