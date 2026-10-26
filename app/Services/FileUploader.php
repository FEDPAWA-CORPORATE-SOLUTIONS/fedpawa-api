<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Parité de src/Service/FileUploader.php :
 * - MIME vérifié sur le CONTENU (finfo), pas sur l'extension ;
 * - extensions autorisées : PDF / JPEG / PNG uniquement ;
 * - dossier par client, nom aléatoire conservé (uniqid('doc_')_nom) ;
 * - taille max 10 Mo (règle affichée par le tunnel, étape 3).
 * Retourne le chemin relatif (parité : 'client_<id>/doc_...').
 */
class FileUploader
{
    public const MAX_BYTES = 10485760; // 10 Mo

    private const ALLOWED_MIMES = ['application/pdf', 'image/jpeg', 'image/png'];

    public function upload(UploadedFile $file, string $clientFolder): string
    {
        if (! $file->isValid()) {
            throw new \Exception("Erreur lors de l'upload du fichier.");
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw new \Exception('Fichier trop volumineux (max 10 Mo).');
        }

        // Vérification MIME type sur le contenu réel (finfo), comme la source.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($file->getRealPath());
        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new \Exception('Format de fichier non autorisé.');
        }

        $filename = uniqid('doc_').'_'.basename($file->getClientOriginalName());
        $path = $clientFolder.'/'.$filename;

        if (! Storage::disk('local')->put('uploads/'.$path, file_get_contents($file->getRealPath()))) {
            throw new \Exception("Erreur lors de l'enregistrement du fichier.");
        }

        return $path;
    }
}
