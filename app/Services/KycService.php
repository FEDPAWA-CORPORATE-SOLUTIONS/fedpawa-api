<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Parité de src/Service/KycService.php : upload des deux pièces via
 * FileUploader (MIME réel, 10 Mo max), insertion kyc statut 'en_cours',
 * audit KYC_SUBMITTED. Transaction : aucun fichier persisté si échec.
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

            DB::table('kyc')->insert([
                'client_id' => $clientId,
                'piece_identite' => $paths['piece_identite'],
                'justificatif_domicile' => $paths['justificatif_domicile'],
                'statuts' => 'en_cours',
                'date_soumission' => now()->format('Y-m-d H:i:s'),
            ]);

            AuditLogger::log('KYC_SUBMITTED', $clientId, 'client', 'Files: '.implode(', ', $paths));

            return true;
        });
    }
}
