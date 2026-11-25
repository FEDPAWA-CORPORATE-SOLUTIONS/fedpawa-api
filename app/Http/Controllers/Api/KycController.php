<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\KycDecisionRequest;
use App\Http\Requests\KycSubmitRequest;
use App\Mail\EventMail;
use App\Services\AuditLogger;
use App\Services\KycService;
use App\Services\PushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use ZipArchive;

/**
 * Parité de src/Controller/KycController.php :
 * - submit : client connecté, 2 fichiers (MIME réel, 10 Mo) ;
 * - approve/reject : admin requis, statuts 'approuvé'/'rejeté' (dossier global) ;
 * - documentDecision : admin requis, décision sur UN document (pièce identité ou justificatif) ;
 * - completeBatch : admin requis, marque plusieurs documents "à compléter" + email groupé ;
 * - kycClients : liste des clients ayant au moins un document en cours ;
 * - kycClientDocuments : détail des 2 documents d'un client.
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

    /** POST /api/v1/admin/kyc/approve (dossier global) */
    public function approve(KycDecisionRequest $request): JsonResponse
    {
        return $this->decide($request, 'approuvé', 'KYC_APPROVED', 'Dossier KYC approuvé');
    }

    /** POST /api/v1/admin/kyc/reject (dossier global) */
    public function reject(KycDecisionRequest $request): JsonResponse
    {
        return $this->decide($request, 'rejeté', 'KYC_REJECTED', 'Dossier KYC rejeté');
    }

    /**
     * POST /api/v1/admin/document/decision
     * Décision sur un document individuel.
     * Body : { client_id, document_type: 'piece_identite'|'justificatif_domicile', decision: 'approuvé'|'rejeté', motif?: string }
     */
    public function documentDecision(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'min:1'],
            'document_type' => ['required', Rule::in(['piece_identite', 'justificatif_domicile'])],
            'decision' => ['required', Rule::in(['approuvé', 'rejeté'])],
            'motif' => ['nullable', 'string', 'max:1000'],
        ]);

        $clientId = (int) $data['client_id'];
        $documentType = $data['document_type'];
        $decision = $data['decision'];
        $motif = trim((string) ($data['motif'] ?? ''));

        if ($decision === 'rejeté' && $motif === '') {
            return response()->json(['error' => 'Un motif de rejet est requis'], 400);
        }

        $kyc = DB::table('kyc')->where('client_id', $clientId)->orderByDesc('id')->first();
        if (! $kyc) {
            return response()->json(['error' => 'Aucun dossier KYC trouvé pour ce client'], 404);
        }

        $statutColumn = $documentType === 'piece_identite' ? 'piece_identite_statut' : 'justificatif_domicile_statut';
        $motifColumn = $documentType === 'piece_identite' ? 'piece_identite_motif' : 'justificatif_domicile_motif';

        $updateData = [
            $statutColumn => $decision,
            $motifColumn => $decision === 'rejeté' ? $motif : null,
            'date_decision' => now()->format('Y-m-d H:i:s'),
        ];

        DB::transaction(function () use ($kyc, $documentType, $decision, $motif, $statutColumn, $motifColumn, &$updateData) {
            // Recalcul du statut global : si les deux documents sont décidés, le dossier l'est aussi.
            $fresh = DB::table('kyc')->where('id', (int) $kyc->id)->first();
            $idStatut = $fresh->piece_identite_statut ?? 'en_cours';
            $domStatut = $fresh->justificatif_domicile_statut ?? 'en_cours';
            if ($idStatut !== 'en_cours' && $domStatut !== 'en_cours') {
                $bothApproved = $idStatut === 'approuvé' && $domStatut === 'approuvé';
                $updateData['statuts'] = $bothApproved ? 'approuvé' : 'rejeté';
            }

            DB::table('kyc')->where('id', (int) $kyc->id)->update($updateData);
        });

        AuditLogger::log('KYC_DOCUMENT_DECISION', $clientId, 'admin', "Document {$documentType} → {$decision}".($motif !== '' ? ' | motif: '.$motif : ''));

        // Notification + email client pour ce document
        $this->notifyClientDocumentDecision((int) $kyc->client_id, (int) $kyc->id, $documentType, $decision, $motif);

        return response()->json(['message' => 'Décision enregistrée pour le document '.$documentType]);
    }

    /**
     * POST /api/v1/admin/kyc/client/{id}/complete-batch
     * Marque plusieurs documents "à compléter" avec motifs, puis envoie UN seul email groupé.
     * Body : { events: [{ document_type, motif }] }
     */
    public function completeBatch(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'events' => ['required', 'array', 'min:1'],
            'events.*.document_type' => ['required', Rule::in(['piece_identite', 'justificatif_domicile'])],
            'events.*.motif' => ['required', 'string', 'max:1000'],
        ]);

        $clientId = $id;
        $events = $data['events'];

        $kyc = DB::table('kyc')->where('client_id', $clientId)->orderByDesc('id')->first();
        if (! $kyc) {
            return response()->json(['error' => 'Aucun dossier KYC trouvé pour ce client'], 404);
        }

        $dateMaj = now()->format('d/m/Y');
        $lien = config('app.url', 'https://www.fedpawa.com').'/espace-client?tab=kyc';

        // Mise à jour de chaque document concerné
        $details = [];
        DB::transaction(function () use ($kyc, $events, $clientId, &$details) {
            foreach ($events as $evt) {
                $documentType = $evt['document_type'];
                $motif = trim((string) $evt['motif']);
                $statutColumn = $documentType === 'piece_identite' ? 'piece_identite_statut' : 'justificatif_domicile_statut';
                $motifColumn = $documentType === 'piece_identite' ? 'piece_identite_motif' : 'justificatif_domicile_motif';

                DB::table('kyc')->where('id', (int) $kyc->id)->update([
                    $statutColumn => 'à_compléter',
                    $motifColumn => $motif,
                    'date_decision' => now()->format('Y-m-d H:i:s'),
                ]);

                $details[] = [
                    'document' => $documentType === 'piece_identite' ? 'Pièce d\'identité' : 'Justificatif de domicile',
                    'action' => 'À compléter',
                    'motif' => $motif,
                ];

                AuditLogger::log('KYC_DOCUMENT_COMPLETE', $clientId, 'admin', "Document {$documentType} → à_compléter | motif: {$motif}");
            }
        });

        // Notification push client (best-effort)
        $client = DB::table('clients')->where('id', $clientId)->first();
        $clientEmail = $client ? $client->email : null;
        $clientNom = $client ? ($client->nom ?? 'Client') : 'Client';

        if ($client && $client->client_id ?? $client->id) {
            $this->push->notifyEvent(
                $clientId,
                'dossier_statut',
                'Documents KYC à compléter',
                'Certains de vos documents KYC doivent être corrigés ou complétés. Consultez votre espace client.',
                '/espace-client?tab=kyc'
            );
        }

        // EMAIL GROUPÉ : un seul mail avec tous les événements
        $this->sendBatchCompleteEmail($clientEmail, $clientNom, (int) $kyc->id, $dateMaj, $lien, $details);

        return response()->json([
            'message' => 'Documents marqués "à compléter". Client notifié par un seul email récapitulatif.',
            'events' => $details,
        ]);
    }

    /** GET /api/v1/admin/kyc/clients — liste des clients avec KYC en cours */
    public function kycClients(Request $request): JsonResponse
    {
        $rows = DB::table('kyc as k')
            ->join('clients as c', 'c.id', '=', 'k.client_id')
            ->where(function ($q) {
                $q->where('k.piece_identite_statut', 'en_cours')
                    ->orWhere('k.justificatif_domicile_statut', 'en_cours')
                    ->orWhere('k.piece_identite_statut', 'à_compléter')
                    ->orWhere('k.justificatif_domicile_statut', 'à_compléter')
                    ->orWhere('k.statuts', 'en_cours');
            })
            ->select('c.id as client_id', 'c.nom', 'c.entreprise', 'c.email', 'c.role',
                'k.id as kyc_id', 'k.statuts', 'k.date_soumission',
                'k.piece_identite_statut', 'k.justificatif_domicile_statut',
                'k.piece_identite_motif', 'k.justificatif_domicile_motif')
            ->orderByDesc('k.date_soumission')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        return response()->json(['clients' => $rows]);
    }

    /** GET /api/v1/admin/kyc/client/{id}/documents — détail des 2 documents d'un client */
    public function kycClientDocuments(int $id): JsonResponse
    {
        $client = DB::table('clients')->where('id', $id)->first();
        if (! $client) {
            return response()->json(['error' => 'Client introuvable'], 404);
        }

        $kyc = DB::table('kyc')->where('client_id', $id)->orderByDesc('id')->first();
        if (! $kyc) {
            return response()->json(['error' => 'Aucun dossier KYC pour ce client'], 404);
        }

        return response()->json([
            'client' => (array) $client,
            'kyc' => (array) $kyc,
            'documents' => [
                [
                    'type' => 'piece_identite',
                    'label' => 'Pièce d\'identité',
                    'chemin' => $kyc->piece_identite,
                    'statut' => $kyc->piece_identite_statut ?? 'en_cours',
                    'motif' => $kyc->piece_identite_motif,
                ],
                [
                    'type' => 'justificatif_domicile',
                    'label' => 'Justificatif de domicile',
                    'chemin' => $kyc->justificatif_domicile,
                    'statut' => $kyc->justificatif_domicile_statut ?? 'en_cours',
                    'motif' => $kyc->justificatif_domicile_motif,
                ],
            ],
        ]);
    }

    private function decide(KycDecisionRequest $request, string $statut, string $auditAction, string $message): JsonResponse
    {
        try {
            $id = (int) $request->input('id');
            $motif = trim((string) ($request->input('motif') ?? ''));

            if ($statut === 'rejeté' && $motif === '') {
                return response()->json(['error' => 'Un motif de rejet est requis'], 400);
            }

            $updateData = [
                'statuts' => $statut,
                'date_decision' => now()->format('Y-m-d H:i:s'),
            ];
            if ($statut === 'rejeté') {
                $updateData['motif_rejet'] = $motif;
            }

            // Mise à jour aussi des statuts individuels des documents (cohérence)
            if ($statut === 'approuvé') {
                $updateData['piece_identite_statut'] = 'approuvé';
                $updateData['justificatif_domicile_statut'] = 'approuvé';
                $updateData['piece_identite_motif'] = null;
                $updateData['justificatif_domicile_motif'] = null;
            } elseif ($statut === 'rejeté') {
                $updateData['piece_identite_statut'] = 'rejeté';
                $updateData['justificatif_domicile_statut'] = 'rejeté';
                $updateData['piece_identite_motif'] = $motif;
                $updateData['justificatif_domicile_motif'] = $motif;
            }

            $updated = DB::table('kyc')->where('id', $id)->update($updateData);
            if ($updated === 0) {
                return response()->json(['error' => 'Dossier KYC introuvable'], 404);
            }
            AuditLogger::log($auditAction, $id, 'admin', 'KYC ID: '.$id.($motif !== '' ? ' | motif: '.$motif : ''));

            // Récupération du dossier et du client concerné
            $kyc = DB::table('kyc')->where('id', $id)->first();
            $clientEmail = null;
            $clientNom = 'Client';
            if ($kyc && $kyc->client_id) {
                $client = DB::table('clients')->where('id', (int) $kyc->client_id)->first();
                if ($client) {
                    $clientEmail = $client->email;
                    $clientNom = $client->nom ?? 'Client';
                }
            }

            // Notification catalogue : dossier_statut (push best-effort, jamais fatal)
            if ($kyc && $kyc->client_id) {
                $dateMaj = now()->format('d/m/Y');
                $lien = config('app.url', 'https://www.fedpawa.com').'/espace-client?tab=kyc';
                if ($statut === 'approuvé') {
                    $this->push->notifyEvent(
                        (int) $kyc->client_id,
                        'dossier_statut',
                        'Vos documents KYC sont validés',
                        'Votre dossier KYC a été approuvé par notre équipe.',
                        '/espace-client?tab=kyc'
                    );
                    $this->envoyerMailKycClient($clientEmail, $clientNom, (int) $id, $dateMaj, $lien,
                        'Vos documents KYC sont validés — FEDPAWA',
                        'emails.08a-dossier-kyc-approuve',
                        ['kyc_id' => (int) $id, 'date_maj' => $dateMaj, 'lien_action' => $lien]
                    );
                } else {
                    $this->push->notifyEvent(
                        (int) $kyc->client_id,
                        'dossier_statut',
                        'Documents KYC à corriger',
                        'Votre dossier KYC a été rejeté. '.$motif,
                        '/espace-client?tab=kyc'
                    );
                    $this->envoyerMailKycClient($clientEmail, $clientNom, (int) $id, $dateMaj, $lien,
                        'Documents KYC à corriger — FEDPAWA',
                        'emails.08b-dossier-kyc-rejete',
                        ['kyc_id' => (int) $id, 'date_maj' => $dateMaj, 'motif_rejet' => $motif, 'lien_action' => $lien]
                    );
                }
            }

            return response()->json(['message' => $message]);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['error' => 'Erreur interne'], 500);
        }
    }

    /**
     * Notifie le client pour une décision sur un document individuel.
     */
    private function notifyClientDocumentDecision(int $clientId, int $kycId, string $documentType, string $decision, ?string $motif): void
    {
        $client = DB::table('clients')->where('id', $clientId)->first();
        if (! $client) {
            return;
        }
        $documentLabel = $documentType === 'piece_identite' ? 'pièce d\'identité' : 'justificatif de domicile';
        $dateMaj = now()->format('d/m/Y');
        $lien = config('app.url', 'https://www.fedpawa.com').'/espace-client?tab=kyc';

        if ($decision === 'approuvé') {
            $this->push->notifyEvent(
                $clientId,
                'dossier_statut',
                'Votre '.$documentLabel.' est validé',
                'Votre '.$documentLabel.' KYC a été approuvé par notre équipe.',
                '/espace-client?tab=kyc'
            );
            $this->envoyerMailKycClient($client->email, $client->nom ?? 'Client', $kycId, $dateMaj, $lien,
                'Votre '.$documentLabel.' KYC est validé — FEDPAWA',
                'emails.08a-dossier-kyc-approuve',
                ['kyc_id' => $kycId, 'date_maj' => $dateMaj, 'lien_action' => $lien,
                 'document_label' => $documentLabel]
            );
        } else {
            $this->push->notifyEvent(
                $clientId,
                'dossier_statut',
                'Votre '.$documentLabel.' est rejeté',
                'Votre '.$documentLabel.' KYC a été rejeté. '.($motif ?? ''),
                '/espace-client?tab=kyc'
            );
            $this->envoyerMailKycClient($client->email, $client->nom ?? 'Client', $kycId, $dateMaj, $lien,
                'Votre '.$documentLabel.' KYC est rejeté — FEDPAWA',
                'emails.08b-dossier-kyc-rejete',
                ['kyc_id' => $kycId, 'date_maj' => $dateMaj, 'motif_rejet' => $motif, 'lien_action' => $lien,
                 'document_label' => $documentLabel]
            );
        }
    }

    /**
     * Envoie un EMAIL GROUPÉ pour un batch "à compléter".
     * Un seul mail contient tous les événements (documents à compléter avec motifs).
     */
    private function sendBatchCompleteEmail(?string $email, string $clientNom, int $kycId, string $dateMaj, string $lien, array $details): void
    {
        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $documentsList = '';
        foreach ($details as $i => $d) {
            $documentsList .= '<tr><td style="padding:8px;border:1px solid #E2DDD5;">'.($i + 1).'</td>'
                .'<td style="padding:8px;border:1px solid #E2DDD5;">'.htmlspecialchars($d['document']).'</td>'
                .'<td style="padding:8px;border:1px solid #E2DDD5;">À compléter</td>'
                .'<td style="padding:8px;border:1px solid #E2DDD5;">'.htmlspecialchars($d['motif']).'</td></tr>';
        }
        $htmlBody = 'Bonjour <strong>'.htmlspecialchars($clientNom).'</strong>,<br><br>'
            .'Certains de vos documents KYC doivent être complétés ou corrigés :<br><br>'
            .'<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;margin:16px 0;">'
            .'<tr style="background:#FAF9F6;"><th style="padding:8px;border:1px solid #E2DDD5;text-align:left;">#</th>'
            .'<th style="padding:8px;border:1px solid #E2DDD5;text-align:left;">Document</th>'
            .'<th style="padding:8px;border:1px solid #E2DDD5;text-align:left;">Action</th>'
            .'<th style="padding:8px;border:1px solid #E2DDD5;text-align:left;">Motif</th></tr>'
            .$documentsList.'</table><br>'
            .'Merci de soumettre à nouveau les documents concernés depuis votre espace client.<br><br>'
            .'<a href="'.$lien.'" style="display:inline-block;background:#0A1424;color:#fff;padding:12px 24px;text-decoration:none;">Accéder à mon espace client</a><br><br>'
            .'FEDPAWA Corporate Solutions';

        try {
            Mail::to($email)->send(new EventMail('kyc_batch_complete_'.$kycId,
                'Documents KYC à compléter — FEDPAWA',
                'emails.layout',
                ['client_nom' => $clientNom, 'client_email' => $email, 'annee' => now()->year,
                 'kyc_id' => $kycId, 'date_maj' => $dateMaj, 'lien_action' => $lien,
                 'titre_page' => 'Documents KYC à compléter — FEDPAWA',
                 'preheader' => 'Certains de vos documents KYC doivent être complétés.',
                 'content' => $htmlBody]));
        } catch (\Throwable $e) {
            \Log::warning('E-mail groupé KYC à compléter non envoyé à '.$email.': '.$e->getMessage());
        }
    }

    /**
     * Envoie l'e-mail de décision KYC au client (best-effort : jamais fatal).
     *
     * @param  array<string,string>  $donnees
     * @return void
     */
    private function envoyerMailKycClient(?string $email, string $clientNom, int $kycId, string $dateMaj, string $lien, string $sujet, string $vue, array $donnees): void
    {
        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $donnees = array_merge([
            'client_nom' => $clientNom,
            'client_email' => $email,
            'annee' => now()->year,
            'kyc_id' => $kycId,
            'date_maj' => $dateMaj,
            'lien_action' => $lien,
        ], $donnees);
        try {
            Mail::to($email)->send(new EventMail('kyc_'.$kycId, $sujet, $vue, $donnees));
        } catch (\Throwable $e) {
            \Log::warning('E-mail décision KYC non envoyé à '.$email.': '.$e->getMessage());
        }
}

    /** GET /api/v1/kyc/download-zip — téléchargement groupé des documents KYC du client connecté */
    public function downloadZip(Request $request)
    {
        $user = $request->user();
        $client = DB::table('clients')->where('email', $user->email)->first();
        if (! $client) {
            abort(403, 'Non autorisé');
        }

        $kyc = DB::table('kyc')->where('client_id', (int) $client->id)->orderByDesc('id')->first();
        if (! $kyc) {
            abort(404, 'Aucun dossier KYC trouvé pour ce client');
        }

        $documents = [
            'piece_identite' => $kyc->piece_identite ?? null,
            'justificatif_domicile' => $kyc->justificatif_domicile ?? null,
        ];

        $tempFile = tempnam(sys_get_temp_dir(), 'kyc_zip_') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Impossible de creer l archive ZIP');
        }

        foreach ($documents as $label => $chemin) {
            if (! $chemin) continue;
            $diskPath = Storage::disk('local')->path('uploads/' . $chemin);
            if (file_exists($diskPath)) {
                $zip->addFile($diskPath, $label . '.' . pathinfo($chemin, PATHINFO_EXTENSION));
            }
        }

        $zip->close();

        return response()->download($tempFile, 'kyc_documents_' . $client->id . '.zip', [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="kyc_documents_' . $client->id . '.zip"',
        ])->deleteFileAfterSend(true);
    }

}
