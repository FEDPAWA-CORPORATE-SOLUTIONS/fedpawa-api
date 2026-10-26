<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\PdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Parité de src/Controller/StorageController.php :
 * - GET /api/v1/document?type=courrier|kyc|facture|contrat&id=…[&file=…] :
 *   sert le PDF (inline, headers identiques), ownership client/admin,
 *   anti path-traversal (résolution sous storage/uploads ou storage),
 *   génération à la volée des factures sans lien_pdf (L019) ;
 * - GET /api/v1/admin/document-info : métadonnées (admin), exists/size/mime.
 *
 * Chemins stockés relatifs à storage/uploads/ (courriers, KYC) ou
 * storage/ (contrats, documents/factures) — mêmes bases que la source.
 */
class StorageController extends Controller
{
    private const ALLOWED_INFO_TYPES = ['contrat', 'courrier', 'kyc'];

    /** GET /api/v1/document */
    public function serve(Request $request): StreamedResponse|JsonResponse
    {
        $type = (string) $request->query('type', '');
        $id = (int) $request->query('id', 0);
        $file = (string) $request->query('file', '');

        if ($id <= 0) {
            return response()->json(['error' => 'ID invalide'], 400);
        }

        try {
            [$row, $relPath, $filename] = $this->resolveDocument($type, $id, $file);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }

        if (! $row) {
            return response()->json(['error' => 'Document introuvable'], 404);
        }

        // Ownership : client => ses documents uniquement ; admin => tout
        $viewer = $request->user();
        if (! $viewer) {
            return response()->json(['error' => 'Non autorisé'], 403);
        }
        $client = DB::table('clients')->where('email', $viewer->email)->first();
        if (! $client) {
            return response()->json(['error' => 'Non autorisé'], 403);
        }
        $isAdmin = ($client->role ?? 'client') === 'admin';
        if (! $isAdmin && (int) $client->id !== (int) $row->client_id) {
            return response()->json(['error' => 'Non autorisé'], 403);
        }

        if ($relPath === '') {
            return response()->json(['error' => 'Fichier indisponible'], 404);
        }

        $full = $this->resolvePhysical($relPath);
        if ($full === null) {
            return response()->json(['error' => 'Accès au fichier refusé'], 403);
        }

        AuditLogger::log(
            'DOCUMENT_VIEWED',
            $isAdmin ? null : (int) $client->id,
            $isAdmin ? 'admin' : 'client',
            "$type #$id"
        );

        return response()->stream(function () use ($full) {
            readfile($full);
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }

    /** GET /api/v1/admin/document-info */
    public function documentInfo(Request $request): JsonResponse
    {
        $viewer = $request->user();
        if (! $viewer) {
            return response()->json(['error' => 'Non autorisé'], 403);
        }
        $viewerClient = DB::table('clients')->where('email', $viewer->email)->first();
        if (! $viewerClient || ($viewerClient->role ?? 'client') !== 'admin') {
            return response()->json(['error' => 'Non autorisé'], 403);
        }

        $type = (string) $request->query('type', '');
        $id = (int) $request->query('id', 0);
        $file = (string) $request->query('file', '');
        if ($id <= 0) {
            return response()->json(['error' => 'ID invalide'], 400);
        }
        if (! in_array($type, self::ALLOWED_INFO_TYPES, true)) {
            return response()->json(['error' => 'Type de document invalide'], 400);
        }

        try {
            switch ($type) {
                case 'contrat':
                    $row = DB::table('contrats as ct')
                        ->leftJoin('clients as c', 'c.id', '=', 'ct.client_id')
                        ->where('ct.id', $id)
                        ->first(['ct.fichier', 'ct.type', 'ct.date_signature', 'c.nom as client_nom', 'c.entreprise']);
                    if (! $row) {
                        return response()->json(['error' => 'Document introuvable'], 404);
                    }
                    $relPath = (string) ($row->fichier ?? '');
                    $filename = 'contrat_'.$id.'.pdf';
                    $date = $row->date_signature;
                    $typeLabel = 'Contrat '.($row->type ?? '');
                    break;

                case 'courrier':
                    $row = DB::table('courriers as co')
                        ->leftJoin('clients as c', 'c.id', '=', 'co.client_id')
                        ->where('co.id', $id)
                        ->first(['co.scan', 'co.date_reception', 'c.nom as client_nom', 'c.entreprise']);
                    if (! $row) {
                        return response()->json(['error' => 'Document introuvable'], 404);
                    }
                    $relPath = (string) ($row->scan ?? '');
                    $filename = 'courrier_'.$id.'.pdf';
                    $date = $row->date_reception;
                    $typeLabel = 'Courrier (scan)';
                    break;

                case 'kyc':
                    if (! in_array($file, ['piece_identite', 'justificatif_domicile'], true)) {
                        return response()->json(['error' => 'Fichier invalide'], 400);
                    }
                    $row = DB::table('kyc as k')
                        ->join('clients as c', 'c.id', '=', 'k.client_id')
                        ->where('k.id', $id)
                        ->first(['k.'.$file.' as rel', 'k.date_soumission', 'c.nom as client_nom', 'c.entreprise']);
                    if (! $row) {
                        return response()->json(['error' => 'Document introuvable'], 404);
                    }
                    $relPath = (string) ($row->rel ?? '');
                    $filename = $relPath !== '' ? basename($relPath) : 'kyc_'.$id.'_'.$file.'.pdf';
                    $date = $row->date_soumission;
                    $typeLabel = $file === 'piece_identite' ? 'KYC — Pièce d\'identité' : 'KYC — Justificatif de domicile';
                    break;
            }
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Erreur interne'], 500);
        }

        $exists = false;
        $full = null;
        if ($relPath !== '' && ! str_contains($relPath, '..')) {
            $full = $this->resolvePhysical($relPath);
            $exists = $full !== null;
        }

        return response()->json([
            'exists' => $exists,
            'filename' => $exists ? basename($full) : $filename,
            'size' => $exists ? filesize($full) : null,
            'mime' => $exists ? (mime_content_type($full) ?: 'application/octet-stream') : null,
            'date' => $date,
            'client_nom' => $row->client_nom ?? null,
            'entreprise' => $row->entreprise ?? null,
            'type_label' => $typeLabel,
            'download_url' => $exists
                ? '/api/v1/document?type='.urlencode($type).'&id='.$id.($type === 'kyc' ? '&file='.urlencode($file) : '')
                : null,
        ]);
    }

    /**
     * @return array{0: object|null, 1: string, 2: string} row, relPath, filename
     */
    private function resolveDocument(string $type, int $id, string $file): array
    {
        switch ($type) {
            case 'courrier':
                $row = DB::table('courriers')->where('id', $id)->first(['id', 'client_id', 'scan']);

                return [$row, (string) ($row->scan ?? ''), 'courrier_'.$id.'.pdf'];

            case 'kyc':
                if (! in_array($file, ['piece_identite', 'justificatif_domicile'], true)) {
                    throw new \InvalidArgumentException('Fichier invalide');
                }
                $row = DB::table('kyc')->where('id', $id)->first(['id', 'client_id', 'piece_identite', 'justificatif_domicile']);

                return [$row, (string) ($row->{$file} ?? ''), 'kyc_'.$id.'_'.$file.'.pdf'];

            case 'facture':
                $row = DB::table('factures')->where('id', $id)->first();
                if ($row && empty($row->lien_pdf)) {
                    // L019 : génération à la volée des anciennes factures
                    $client = DB::table('clients')->where('id', (int) $row->client_id)->first(['nom']);
                    $pdf = app(PdfService::class)->generateInvoice([
                        'invoice_number' => $row->numero,
                        'client_name' => $client->nom ?? 'Client',
                        'amount' => (float) $row->montant,
                    ]);
                    Storage::disk('local')->put('documents/facture_'.$id.'.pdf', $pdf);
                    DB::table('factures')->where('id', $id)->update(['lien_pdf' => 'documents/facture_'.$id.'.pdf']);
                    $row->lien_pdf = 'documents/facture_'.$id.'.pdf';
                }

                return [$row, (string) ($row->lien_pdf ?? ''), 'facture_'.$id.'.pdf'];

            case 'contrat':
                $row = DB::table('contrats')->where('id', $id)->first(['id', 'client_id', 'fichier']);

                return [$row, (string) ($row->fichier ?? ''), 'contrat_'.$id.'.pdf'];

            default:
                throw new \InvalidArgumentException('Type de document invalide');
        }
    }

    /**
     * Anti path-traversal : résolution sous le disque local
     * (storage/app/private — cf. FileUploader) : uploads/ puis racine disque
     * (documents/factures_*.pdf). Le chemin résolu DOIT rester sous la
     * racine du disque — retourne null sinon.
     */
    private function resolvePhysical(string $relPath): ?string
    {
        if ($relPath === '' || str_contains($relPath, '..')) {
            return null;
        }
        $base = realpath(Storage::disk('local')->path(''));
        if ($base === false) {
            return null;
        }
        foreach ([$base.DIRECTORY_SEPARATOR.'uploads', $base] as $candidateBase) {
            $resolved = realpath($candidateBase.DIRECTORY_SEPARATOR.$relPath);
            if ($resolved !== false
                && str_starts_with($resolved, $base.DIRECTORY_SEPARATOR)
                && is_file($resolved)) {
                return $resolved;
            }
        }

        return null;
    }
}
