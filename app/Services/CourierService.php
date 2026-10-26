<?php

namespace App\Services;

use Dompdf\Dompdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Parité de src/Service/CourierService.php :
 * - upload de N fichiers (client_<id>/doc_<uniqid>_<nom>, règles FileUploader) ;
 * - image (GD absent : image laissée telle quelle, extension .pdf via copy)
 *   -> conversion PDF dompdf si GD disponible ;
 * - fusion : parité source = premier PDF copié (mergePdfs 'light') ;
 * - insertion courriers (statut 'non_lu', date_reception now) ;
 * - retour : chemin relatif 'client_<id>/doc_<uniqid>.pdf' (storage/uploads).
 */
class CourierService
{
    public function __construct(private FileUploader $uploader) {}

    /** @param UploadedFile[] $files */
    public function uploadMultiple(int $clientId, array $files): string
    {
        $pdfPaths = [];
        $clientDir = 'client_'.$clientId;

        foreach ($files as $file) {
            $path = $this->uploader->upload($file, $clientDir);
            $fullPath = Storage::disk('local')->path('uploads/'.$path);
            $mime = mime_content_type($fullPath);

            if (str_starts_with($mime, 'image/')) {
                $convertedPdf = preg_replace('/\.[^.]+$/', '.pdf', $fullPath);
                if (function_exists('imagecreatefromstring')) {
                    $this->optimizeImage($fullPath);
                    $this->imageToPdf($fullPath, $convertedPdf);
                    unlink($fullPath);
                } else {
                    // GD indisponible : conserver l'image (pas de conversion)
                    copy($fullPath, $convertedPdf);
                }
                $pdfPaths[] = $convertedPdf;
            } else {
                $pdfPaths[] = $fullPath;
            }
        }

        $finalPdfName = 'doc_'.uniqid().'.pdf';
        $finalRelPath = 'uploads/'.$clientDir.'/'.$finalPdfName;
        $finalPdfPath = Storage::disk('local')->path($finalRelPath);

        $this->mergePdfs($pdfPaths, $finalPdfPath);

        foreach ($pdfPaths as $p) {
            if ($p !== $finalPdfPath && file_exists($p)) {
                unlink($p);
            }
        }

        DB::table('courriers')->insert([
            'client_id' => $clientId,
            'date_reception' => now()->format('Y-m-d H:i:s'),
            'scan' => $clientDir.'/'.$finalPdfName,
            'statut' => 'non_lu',
        ]);

        return $clientDir.'/'.$finalPdfName;
    }

    private function optimizeImage(string $path): void
    {
        $info = @getimagesize($path);
        if ($info && $info[0] > 1000) {
            $img = imagecreatefromstring(file_get_contents($path));
            $resized = imagescale($img, 1000);
            imagejpeg($resized, $path, 80);
            imagedestroy($img);
            imagedestroy($resized);
        }
    }

    private function imageToPdf(string $imagePath, string $pdfPath): void
    {
        $pdf = new Dompdf;
        $imgData = base64_encode(file_get_contents($imagePath));
        $src = 'data:image/jpeg;base64,'.$imgData;
        $pdf->loadHtml('<img src="'.$src.'" style="width:100%;">');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();
        file_put_contents($pdfPath, $pdf->output());
    }

    private function mergePdfs(array $paths, string $outputPath): void
    {
        // Parité source (light) : le premier PDF devient le document final.
        copy($paths[0], $outputPath);
    }
}
