<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Parité de src/Controller/FactureController.php : GET /api/factures
 * (liste des factures du client connecté, ordre date DESC),
 * + téléchargement PDF d'une facture (Storage local).
 */
class InvoiceController extends Controller
{
    /** GET /api/v1/factures */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $client = DB::table('clients')->where('email', $user->email)->first();
        if (! $client) {
            return response()->json(['error' => 'Non autorisé'], 403);
        }
        $rows = DB::table('factures')
            ->where('client_id', $client->id)
            ->orderByDesc('date')
            ->get(['id', 'numero', 'montant', 'date', 'lien_pdf']);

        return response()->json(['data' => $rows]);
    }

    /** GET /api/v1/factures/{id}/pdf — serve le PDF stocké (client propriétaire). */
    public function download(Request $request, int $id): Response
    {
        $user = $request->user();
        $client = DB::table('clients')->where('email', $user->email)->first();
        if (! $client) {
            return response(['error' => 'Non autorisé'], 403);
        }
        // Branche admin : l'administrateur peut télécharger TOUTE facture
        // (parité renderContract) ; un client ne voit que ses propres factures.
        $facture = DB::table('factures')->where('id', $id);
        if ($client->role !== 'admin') {
            $facture->where('client_id', $client->id);
        }
        $facture = $facture->first();
        if (! $facture) {
            return response(['error' => 'Facture introuvable'], 404);
        }
        if (! $facture->lien_pdf || ! Storage::disk('local')->exists($facture->lien_pdf)) {
            return response(['error' => 'PDF indisponible'], 404);
        }
        $pdf = Storage::disk('local')->get($facture->lien_pdf);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="facture_'.$facture->numero.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** GET /api/v1/contract/preview — L018 aperçu HTML (client propriétaire). */
    public function previewContract(Request $request): Response
    {
        return $this->renderContract($request, false);
    }

    /** GET /api/v1/contract/download — L022 PDF avant signature. */
    public function downloadContract(Request $request): Response
    {
        return $this->renderContract($request, true);
    }

    private function renderContract(Request $request, bool $asPdf): Response
    {
        $subId = (int) $request->query('subscription_id', 0);
        if ($subId <= 0) {
            return response('Souscription invalide.', 400);
        }
        $sub = DB::table('abonnements')
            ->join('clients', 'clients.id', '=', 'abonnements.client_id')
            ->where('abonnements.id', $subId)
            ->select('abonnements.*', 'clients.nom', 'clients.email', 'clients.entreprise')
            ->first();
        if (! $sub) {
            return response('Souscription introuvable.', 404);
        }
        // Garde : client propriétaire (admin géré par policy du worker auth)
        $user = $request->user();
        $isOwner = $user && (int) $user->id === (int) $sub->client_id;
        $isAdmin = $user && DB::table('clients')->where('email', $user->email)->where('role', 'admin')->exists();
        if (! $isAdmin && ! $isOwner && $user->email !== $sub->email) {
            return response('Accès refusé.', 403);
        }

        $clientName = trim((string) ($sub->nom ?? '')) ?: 'Client';
        $companyName = trim((string) ($sub->entreprise ?? '')) ?: $clientName;
        $price = (int) ($sub->prix ?? 0);

        $pdfService = new PdfService;
        if ($asPdf) {
            $pdf = $pdfService->generateContract([
                'client_name' => $clientName,
                'company_name' => $companyName,
                'price' => $price,
                'details' => $sub->details ?? null,
            ]);

            return response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="contrat_domiciliation_'.$subId.'.pdf"',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        // Aperçu HTML : contenu BDD/fichier avec substitutions (parité preview()).
        $content = $pdfService->contractTemplateContent();
        $content = str_replace(
            ['{{ client_name }}', "{{ company_name|default('Entreprise') }}", '{{ company_name }}', '{{ price }}'],
            [$clientName, $companyName, $companyName, number_format($price, 0, ',', ' ').' FCFA'],
            $content
        );
        $content = PdfService::fillDomicilie($content, [
            'client_name' => $clientName,
            'company_name' => $companyName,
            'details' => $sub->details ?? null,
        ]);
        $content = preg_replace('/\{\{[^}]*\}\}/', '…………', (string) $content);
        $safe = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', (string) $content);

        $html = '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>Contrat de domiciliation — FEDPAWA CORPORATE SOLUTIONS</title></head>'
            .'<body><div class="cv-doc">'.$safe.'</div></body></html>';

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
