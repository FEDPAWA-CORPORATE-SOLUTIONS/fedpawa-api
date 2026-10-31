<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\KycDecisionRequest;
use App\Http\Requests\KycSubmitRequest;
use App\Services\AuditLogger;
use App\Services\KycService;
use App\Services\PushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Parité de src/Controller/KycController.php :
 * - submit : client connecté, 2 fichiers (MIME réel, 10 Mo) ;
 * - approve/reject : admin requis, statuts 'approuvé'/'rejeté' ;
 * - 404 si dossier introuvable (rowCount === 0 source).
 */
class KycController extends Controller
{
    public function __construct(private KycService $service, private PushService $push) {}

    /** POST /api/v1/kyc/submit */
    public function submit(KycSubmitRequest $request): JsonResponse
    {
        $user = $request->user();
        $client = DB::table('clients')->where('email', $user->email)->first();
        if (! $client) {
            return response()->json(['error' => 'Non autorisé'], 403);
        }

        try {
            $this->service->submit((int) $client->id, [
                'piece_identite' => $request->file('piece_identite'),
                'justificatif_domicile' => $request->file('justificatif_domicile'),
            ]);

            return response()->json(['message' => 'Dossier KYC soumis']);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /** POST /api/v1/admin/kyc/approve */
    public function approve(KycDecisionRequest $request): JsonResponse
    {
        return $this->decide($request, 'approuvé', 'KYC_APPROVED', 'Dossier KYC approuvé');
    }

    /** POST /api/v1/admin/kyc/reject */
    public function reject(KycDecisionRequest $request): JsonResponse
    {
        return $this->decide($request, 'rejeté', 'KYC_REJECTED', 'Dossier KYC rejeté');
    }

    private function decide(KycDecisionRequest $request, string $statut, string $auditAction, string $message): JsonResponse
    {
        try {
            $id = (int) $request->input('id');
            $updated = DB::table('kyc')->where('id', $id)->update(['statuts' => $statut]);
            if ($updated === 0) {
                return response()->json(['error' => 'Dossier KYC introuvable'], 404);
            }
            AuditLogger::log($auditAction, $id, 'admin', 'KYC ID: '.$id);

            // Notification catalogue : dossier_statut (best-effort, jamais fatal)
            $kyc = DB::table('kyc')->where('id', $id)->first();
            if ($kyc && $kyc->client_id) {
                if ($statut === 'approuvé') {
                    $this->push->notifyEvent(
                        (int) $kyc->client_id,
                        'dossier_statut',
                        'Vos documents KYC sont validés',
                        'Votre dossier KYC a été approuvé par notre équipe.',
                        '/espace-client?tab=kyc'
                    );
                } else {
                    $this->push->notifyEvent(
                        (int) $kyc->client_id,
                        'dossier_statut',
                        'Documents KYC à corriger',
                        'Votre dossier KYC a été rejeté. Merci de soumettre des documents corrigés.',
                        '/espace-client?tab=kyc'
                    );
                }
            }

            return response()->json(['message' => $message]);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['error' => 'Erreur interne'], 500);
        }
    }
}
