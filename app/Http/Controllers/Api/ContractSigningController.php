<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ContractSigningRequest;
use App\Services\PortantSignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContractSigningController extends Controller
{
    public function __construct(
        private PortantSignService $portant
    ) {}

    public function send(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'title' => 'required|string|max:255',
            'pdf_base64' => 'required|string',
            'signer_name' => 'required|string|max:255',
            'signer_email' => 'required|email|max:255',
            'sender_name' => 'nullable|string|max:255',
            'sender_email' => 'nullable|email|max:255',
            'language' => 'nullable|string|in:fr,en,es,de,ja,it,nl,pt-br',
            'source_contract_id' => 'nullable|integer|exists:contrats,id',
        ]);

        if (!$this->portant->isConfigured()) {
            return response()->json([
                'success' => false,
                'error' => 'Portant non configurée côté serveur.',
            ], 500);
        }

        // Vérification de la taille du PDF (limite Portant : 5 Mo)
        $pdfBase64 = $request->string('pdf_base64');
        $pdfDecoded = base64_decode($pdfBase64, true);
        if ($pdfDecoded === false) {
            return response()->json([
                'success' => false,
                'error' => 'Le document PDF n\'est pas un base64 valide.',
            ], 400);
        }
        $pdfSize = strlen($pdfDecoded);
        if ($pdfSize > 5 * 1024 * 1024) {
            return response()->json([
                'success' => false,
                'error' => 'Le document PDF est trop volumineux (' . round($pdfSize / 1024 / 1024, 2) . ' Mo). Limite : 5 Mo.',
            ], 400);
        }

        $sourceContractId = $request->integer('source_contract_id');

        // Vérification de propriété : si un contrat source est lié, l'utilisateur connecté
        // doit en être le client propriétaire.
        if ($sourceContractId) {
            $contrat = DB::table('contrats')->where('id', $sourceContractId)->first(['id', 'client_id']);
            if (!$contrat) {
                return response()->json([
                    'success' => false,
                    'error' => 'Contrat source introuvable.',
                ], 404);
            }
            if ($user && $contrat->client_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'error' => 'Vous n\'êtes pas le propriétaire de ce contrat.',
                ], 403);
            }
        }

        $result = $this->portant->sendForSignature([
            'title' => $request->string('title'),
            'pdf_base64' => $request->string('pdf_base64'),
            'signer_name' => $request->string('signer_name'),
            'signer_email' => $request->string('signer_email'),
            'sender_name' => $request->string('sender_name') ?? ($user?->name ?? null),
            'sender_email' => $request->string('sender_email') ?? ($user?->email ?? null),
            'language' => $request->string('language') ?? 'fr',
            'sender_signs' => true,
        ]);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'],
            ], 500);
        }

        $record = ContractSigningRequest::create([
            'request_id' => $result['request_id'],
            'request_token' => $result['request_token'],
            'source_contract_id' => $sourceContractId,
            'source_document_hash' => $result['document_hash'] ?? null,
            'source_document_size' => strlen(base64_decode($request->string('pdf_base64'), true)),
            'title' => $request->string('title'),
            'signer_email' => $request->string('signer_email'),
            'signer_name' => $request->string('signer_name'),
            'sender_email' => $request->string('sender_email') ?? ($user?->email ?? null),
            'sender_name' => $request->string('sender_name') ?? ($user?->name ?? null),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'status' => 'pending',
            'api_response' => json_encode($result['api_response'] ?? []),
        ]);

        Log::info('Contrat envoyé à Portant', [
            'request_id' => $result['request_id'],
            'signer_email' => $request->string('signer_email'),
            'sender_email' => $record->sender_email,
            'ip_address' => $request->ip(),
            'source_contract_id' => $sourceContractId,
        ]);

        return response()->json([
            'success' => true,
            'request_id' => $result['request_id'],
            'request_token' => $result['request_token'],
            'next_step' => $result['next_step'] ?? null,
            'status' => 'pending',
        ]);
    }

    public function status(Request $request, string $requestId): JsonResponse
    {
        $user = $request->user();
        $result = $this->portant->getStatus($requestId);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'],
            ], 500);
        }

        $record = ContractSigningRequest::where('request_id', $requestId)->first();
        if (!$record) {
            return response()->json([
                'success' => false,
                'error' => 'Demande de signature introuvable.',
            ], 404);
        }

        // Vérification de propriété : seul l'expéditeur peut consulter le statut
        if ($user && $record->sender_email && $user->email !== $record->sender_email) {
            return response()->json([
                'success' => false,
                'error' => 'Accès non autorisé à cette demande.',
            ], 403);
        }

        if ($record && isset($result['status'])) {
            $record->update(['status' => $result['status']]);
        }

        return response()->json([
            'success' => true,
            'request_id' => $requestId,
            'status' => $result['status'],
            'data' => $result['data'] ?? null,
        ]);
    }

    public function download(Request $request, string $requestId): \Illuminate\Http\Response
    {
        $user = $request->user();
        $record = ContractSigningRequest::where('request_id', $requestId)->first();

        if (!$record) {
            return response()->json([
                'success' => false,
                'error' => 'Demande de signature introuvable.',
            ], 404);
        }

        // Vérification de propriété : seul l'expéditeur peut télécharger
        if ($user && $record->sender_email && $user->email !== $record->sender_email) {
            return response()->json([
                'success' => false,
                'error' => 'Accès non autorisé à ce document.',
            ], 403);
        }

        $result = $this->portant->getSignedPdf($requestId, $record->request_token ?? '');

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'],
            ], 500);
        }

        $record->update([
            'status' => 'signed',
            'signed_pdf_url' => 'stored_locally',
        ]);

        return response()->make($result['content'], 200, [
            'Content-Type' => $result['content_type'] ?? 'application/pdf',
            'Content-Disposition' => 'attachment; filename="contrat_signe_'.$requestId.'.pdf"',
            'X-Document-Hash' => $result['document_hash'] ?? '',
        ]);
    }

    /**
     * Liste les contrats de signature du client connecté.
     */
    public function myContracts(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = ContractSigningRequest::query();

        if ($user) {
            $query->where('sender_email', $user->email)
                ->orWhere('signer_email', $user->email);
        }

        $records = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'contracts' => $records->map(function ($r) {
                return [
                    'request_id' => $r->request_id,
                    'title' => $r->title,
                    'status' => $r->status,
                    'signer_email' => $r->signer_email,
                    'sender_email' => $r->sender_email,
                    'signer_name' => $r->signer_name,
                    'sender_name' => $r->sender_name,
                    'created_at' => $r->created_at,
                    'updated_at' => $r->updated_at,
                    'document_hash' => $r->source_document_hash,
                    'signed_pdf_url' => $r->signed_pdf_url,
                    'source_contract_id' => $r->source_contract_id,
                ];
            }),
        ]);
    }

    /**
     * Liste tous les contrats de signature (admin uniquement).
     */
    public function allContracts(Request $request): JsonResponse
    {
        $user = $request->user();

        // Vérification admin
        if (!$user || !$user->can('admin.access')) {
            return response()->json([
                'success' => false,
                'error' => 'Accès admin requis.',
            ], 403);
        }

        $records = ContractSigningRequest::orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'contracts' => $records->map(function ($r) {
                return [
                    'request_id' => $r->request_id,
                    'title' => $r->title,
                    'status' => $r->status,
                    'signer_email' => $r->signer_email,
                    'signer_name' => $r->signer_name,
                    'sender_email' => $r->sender_email,
                    'sender_name' => $r->sender_name,
                    'created_at' => $r->created_at,
                    'updated_at' => $r->updated_at,
                    'document_hash' => $r->source_document_hash,
                    'signed_pdf_url' => $r->signed_pdf_url,
                    'source_contract_id' => $r->source_contract_id,
                    'ip_address' => $r->ip_address,
                ];
            }),
        ]);
    }
}
