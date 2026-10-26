<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Parité de src/Service/FactureService.php (W-10) :
 * - crée la facture d'un paiement reçu et active l'abonnement en attente ;
 * - idempotent : une seule facture par transaction (numero = FAC-<tx>) ;
 * - génère le PDF immédiatement (L019) et remplit lien_pdf ;
 * - active l'abonnement 'en_attente' le plus récent du client (cohérence W-9)
 *   avec date_fin = engagement (mois) ou +1 mois.
 *
 * @return int|null id de la facture créée, null si rien à faire
 */
class FactureService
{
    public static function createForPayment(int $clientId, float $montant, string $transactionId = ''): ?int
    {
        // Idempotence : transaction déjà facturée ?
        if ($transactionId !== '') {
            $chk = DB::table('factures')->where('numero', 'FAC-'.$transactionId)->first();
            if ($chk) {
                return null;
            }
        }

        $numero = 'FAC-'.($transactionId !== '' ? $transactionId : ('PMT-'.$clientId.'-'.time()));

        $factureId = (int) DB::table('factures')->insertGetId([
            'client_id' => $clientId,
            'numero' => $numero,
            'montant' => $montant,
            'date' => now()->format('Y-m-d H:i:s'),
            'lien_pdf' => null,
        ]);

        // L019: générer le PDF immédiatement et remplir lien_pdf
        try {
            $client = DB::table('clients')->where('id', $clientId)->first();
            $pdf = (new PdfService)->generateInvoice([
                'invoice_number' => $numero,
                'client_name' => $client->nom ?? 'Client',
                'amount' => (float) $montant,
            ]);
            Storage::disk('local')->put('documents/facture_'.$factureId.'.pdf', $pdf);
            DB::table('factures')->where('id', $factureId)
                ->update(['lien_pdf' => 'documents/facture_'.$factureId.'.pdf']);
        } catch (\Exception $e) {
            report($e); // parité : error_log, la facture reste sans PDF
        }

        // Activer l'abonnement en attente le plus récent du client (cohérence W-9)
        $pending = DB::table('abonnements')
            ->where('client_id', $clientId)
            ->where('statut', 'en_attente')
            ->orderByDesc('id')
            ->first();
        if ($pending) {
            $engagement = (int) ($pending->engagement ?? 0);
            $months = $engagement > 0 ? $engagement : 1;
            DB::table('abonnements')->where('id', $pending->id)->update([
                'statut' => 'actif',
                'date_debut' => $pending->date_debut ?: now()->format('Y-m-d H:i:s'),
                'date_fin' => now()->addMonths($months)->format('Y-m-d H:i:s'),
            ]);
        }

        return $factureId;
    }
}
