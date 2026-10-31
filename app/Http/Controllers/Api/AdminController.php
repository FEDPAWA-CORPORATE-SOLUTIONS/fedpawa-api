<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TemplateOtpVerifyRequest;
use App\Http\Requests\TemplateUpdateRequest;
use App\Http\Requests\TicketReplyRequest;
use App\Mail\OtpMail;
use App\Services\AuditLogger;
use App\Services\OtpService;
use App\Services\PushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Parité de src/Controller/AdminController.php (API JSON uniquement — les vues
 * HTML dashboard()/settings() restent côté front Next) :
 * - GET  /api/v1/admin/lists              : listes complètes + doc_exists (L011) ;
 * - GET  /api/v1/admin/overview           : stats KPI + messages + tickets + throttled ;
 * - GET  /api/v1/admin/template           : modèle 'contract' + unlocked (L012) ;
 * - POST /api/v1/admin/template/otp-request : OTP 6 chiffres email admin, masqué ;
 * - POST /api/v1/admin/template/otp-verify  : déverrouillage 10 min (5 essais max) ;
 * - POST /api/v1/admin/template/update       : OTP requis, history + audit ;
 * - POST /api/v1/admin/ticket/reply          : réponse ticket, statut 'répondu'.
 *
 * Garde : CheckAdminRole (401 non connecté, 403 client) — parité
 * constructeur source $_SESSION['admin_logged_in'] / role admin.
 * Sessions PHP → cache Laravel (stateless Sanctum).
 */
class AdminController extends Controller
{
    /** Validité du déverrouillage édition template (parité source : 600 s). */
    private const UNLOCK_TTL = 600;

    private const OTP_MAX_ATTEMPTS = 5;

    public function __construct(private PushService $push) {}

    // =========================================================
    // GET /api/v1/admin/lists — listes enrichies (Lane audit + L011)
    // =========================================================
    public function lists(): JsonResponse
    {
        try {
            $clients = DB::table('clients')
                ->orderByDesc('id')->limit(200)
                ->get(['id', 'nom', 'entreprise', 'email', 'telephone', 'date_inscription'])
                ->map(fn ($r) => (array) $r)->all();

            $abonnements = DB::table('abonnements as a')
                ->join('clients as c', 'c.id', '=', 'a.client_id')
                ->orderByDesc('a.id')->limit(200)
                ->get(['a.id', 'a.client_id', 'c.nom as client_nom', 'c.entreprise', 'a.pack', 'a.prix', 'a.date_debut', 'a.date_fin', 'a.statut'])
                ->map(fn ($r) => (array) $r)->all();

            $paiements = DB::table('paiements as p')
                ->leftJoin('clients as c', 'c.id', '=', 'p.client_id')
                ->orderByDesc('p.id')->limit(200)
                ->get(['p.id', 'p.client_id', 'c.nom as client_nom', 'c.entreprise', 'p.montant', 'p.mode_paiement', 'p.transaction_id', 'p.date', 'p.statut'])
                ->map(fn ($r) => (array) $r)->all();

            $courriers = DB::table('courriers as co')
                ->leftJoin('clients as c', 'c.id', '=', 'co.client_id')
                ->orderByDesc('co.id')->limit(200)
                ->get(['co.id', 'co.client_id', 'c.nom as client_nom', 'c.entreprise', 'co.date_reception', 'co.scan', 'co.statut'])
                ->map(fn ($r) => (array) $r)->all();

            $contrats = DB::table('contrats as ct')
                ->leftJoin('clients as c', 'c.id', '=', 'ct.client_id')
                ->orderByDesc('ct.id')->limit(200)
                ->get(['ct.id', 'ct.client_id', 'c.nom as client_nom', 'c.entreprise', 'ct.type', 'ct.fichier', 'ct.date_signature'])
                ->map(fn ($r) => (array) $r)->all();

            $kyc = DB::table('kyc as k')
                ->join('clients as c', 'c.id', '=', 'k.client_id')
                ->orderByDesc('k.id')->limit(200)
                ->get(['k.id', 'k.client_id', 'c.nom as client_nom', 'c.entreprise', 'k.piece_identite', 'k.justificatif_domicile', 'k.statuts', 'k.date_soumission'])
                ->map(fn ($r) => (array) $r)->all();

            $tickets = DB::table('tickets_support as t')
                ->join('clients as c', 'c.id', '=', 't.client_id')
                ->orderByDesc('t.created_at')->limit(100)
                ->get(['t.*', 'c.nom as client_nom'])
                ->map(fn ($r) => (array) $r)->all();

            $messages = DB::table('internal_messages as m')
                ->leftJoin('clients as c', 'c.id', '=', 'm.sender_id')
                ->orderByDesc('m.created_at')->limit(100)
                ->get(['m.id', 'm.sender_id', 'm.sender_type', 'm.receiver_id', 'm.content', 'm.is_read', 'm.created_at', 'c.nom as client_nom', 'c.entreprise'])
                ->map(fn ($r) => (array) $r)->all();

            // L011 — doc_exists : le fichier existe RÉELLEMENT sur le disque.
            // Contrats : storage/documents/ ; courriers + KYC : storage/uploads/.
            $fileExists = function (?string $relPath, bool $uploadsFirst): bool {
                if ($relPath === null || $relPath === '' || str_contains($relPath, '..')) {
                    return false;
                }
                $base = realpath(storage_path());
                if ($base === false) {
                    return false;
                }
                $uploadsBase = $base.DIRECTORY_SEPARATOR.'uploads';
                $candidates = $uploadsFirst ? [$uploadsBase, $base] : [$base, $uploadsBase];
                foreach ($candidates as $candidateBase) {
                    $resolved = realpath($candidateBase.DIRECTORY_SEPARATOR.$relPath);
                    if ($resolved !== false
                        && str_starts_with($resolved, $base.DIRECTORY_SEPARATOR)
                        && is_file($resolved)) {
                        return true;
                    }
                }

                return false;
            };

            foreach ($courriers as &$courrier) {
                $courrier['doc_exists'] = ! empty($courrier['scan']) && $fileExists($courrier['scan'], true);
            }
            unset($courrier);

            foreach ($contrats as &$contrat) {
                $contrat['doc_exists'] = ! empty($contrat['fichier']) && $fileExists($contrat['fichier'], false);
            }
            unset($contrat);

            foreach ($kyc as &$record) {
                $record['doc_exists_identite'] = ! empty($record['piece_identite']) && $fileExists($record['piece_identite'], true);
                $record['doc_exists_justificatif'] = ! empty($record['justificatif_domicile']) && $fileExists($record['justificatif_domicile'], true);
                $record['doc_exists'] = $record['doc_exists_identite'] || $record['doc_exists_justificatif'];
            }
            unset($record);

            return response()->json([
                'clients' => $clients,
                'abonnements' => $abonnements,
                'paiements' => $paiements,
                'courriers' => $courriers,
                'contrats' => $contrats,
                'kyc' => $kyc,
                'tickets' => $tickets,
                'messages' => $messages,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Erreur interne'], 500);
        }
    }

    // =========================================================
    // GET /api/v1/admin/overview — KPI + communications
    // =========================================================
    public function overview(): JsonResponse
    {
        try {
            $stats = [
                'clients' => (int) DB::table('clients')->count(),
                'abonnements' => (int) DB::table('abonnements')->count(),
                'paiements' => (int) DB::table('paiements')->count(),
                'courriers' => (int) DB::table('courriers')->count(),
                'kyc_en_cours' => (int) DB::table('kyc')->where('statuts', 'en_cours')->count(),
                'tickets_open' => (int) DB::table('tickets_support')->where('statut', 'open')->count(),
            ];

            $messages = DB::table('internal_messages')
                ->orderByDesc('created_at')->limit(50)
                ->get()->map(fn ($r) => (array) $r)->all();

            $tickets = DB::table('tickets_support as t')
                ->join('clients as c', 'c.id', '=', 't.client_id')
                ->orderByDesc('t.created_at')->limit(50)
                ->get(['t.*', 'c.nom as client_nom'])
                ->map(fn ($r) => (array) $r)->all();

            // Parité LoginThrottler::listLocked() — verrous actifs uniquement.
            $throttled = DB::table('login_throttle')
                ->whereNotNull('locked_until')
                ->where('locked_until', '>', now())
                ->orderByDesc('locked_until')
                ->get(['identifier', 'user_type', 'ip', 'attempts', 'locked_until'])
                ->map(function ($r) {
                    $row = (array) $r;
                    $row['minutes_remaining'] = (int) ceil((strtotime((string) $r->locked_until) - time()) / 60);

                    return $row;
                })->all();

            return response()->json([
                'stats' => $stats,
                'messages' => $messages,
                'tickets' => $tickets,
                'throttled' => $throttled,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Erreur interne'], 500);
        }
    }

    // =========================================================
    // GET /api/v1/admin/template — contenu + unlocked (L012)
    // =========================================================
    public function getTemplate(Request $request): JsonResponse
    {
        try {
            $template = DB::table('contract_templates')->where('name', 'contract')->first();
            if (! $template) {
                return response()->json(['error' => 'Modèle de contrat introuvable'], 404);
            }

            return response()->json([
                'id' => (int) $template->id,
                'name' => $template->name,
                'content' => (string) $template->content,
                'updated_at' => $template->updated_at,
                'unlocked' => $this->isUnlocked($this->adminId($request)),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Erreur interne'], 500);
        }
    }

    // =========================================================
    // POST /api/v1/admin/template/otp-request — OTP email admin
    // =========================================================
    public function templateOtpRequest(Request $request): JsonResponse
    {
        try {
            $adminId = $this->adminId($request);
            $adminEmail = $this->adminEmail($request);
            if ($adminId === null || $adminEmail === '' || ! filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                return response()->json(['error' => 'Session admin invalide'], 403);
            }

            $otp = OtpService::generate($adminId, 'admin');

            $sent = false;
            try {
                Mail::to($adminEmail)->send(new OtpMail($otp));
                $sent = true;
            } catch (\Throwable $mailEx) {
                Log::error('OTP admin mail envoi échoué: '.$mailEx->getMessage());
            }
            if (! $sent) {
                // SMTP non configuré (local) : le code reste côté serveur,
                // dans les logs — jamais exposé dans la réponse HTTP.
                Log::info('[OTP-ADMIN-DEV] code pour '.$adminEmail.' : '.$otp);
            }

            AuditLogger::log(
                'TEMPLATE_OTP_SENT',
                $adminId,
                'admin',
                'Email: '.$adminEmail.($sent ? ' (SMTP)' : ' (fallback log)')
            );

            // Email masqué a***@domain.tld — jamais l'email complet ni le code.
            $masked = preg_replace('/^(.).*(@.*)$/', '$1***$2', $adminEmail);

            return response()->json(['sent' => true, 'email' => $masked]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Erreur interne'], 500);
        }
    }

    // =========================================================
    // POST /api/v1/admin/template/otp-verify — déverrouillage 10 min
    // =========================================================
    public function templateOtpVerify(TemplateOtpVerifyRequest $request): JsonResponse
    {
        try {
            $adminId = $this->adminId($request);
            if ($adminId === null) {
                return response()->json(['error' => 'Session admin invalide'], 403);
            }

            $otp = preg_replace('/\D/', '', (string) $request->validated('otp'));
            if (strlen($otp) !== 6) {
                return response()->json(['error' => 'Code à 6 chiffres requis'], 400);
            }

            // Rate-limit : 5 mauvaises tentatives max, au-delà le code est purgé.
            $attemptsKey = "template_otp_attempts_{$adminId}";
            $attempts = (int) Cache::get($attemptsKey, 0);
            if ($attempts >= self::OTP_MAX_ATTEMPTS) {
                OtpService::clear($adminId, 'admin');
                Cache::forget($attemptsKey);
                AuditLogger::log('TEMPLATE_OTP_LOCKED', $adminId, 'admin', '5 tentatives invalides');

                return response()->json(['error' => 'Trop de tentatives. Demandez un nouveau code.'], 429);
            }

            if (! OtpService::verify($adminId, $otp, 'admin')) {
                $attempts++;
                Cache::put($attemptsKey, $attempts, now()->addMinutes(10));
                AuditLogger::log('TEMPLATE_OTP_FAILED', $adminId, 'admin', 'Tentative '.$attempts.'/'.self::OTP_MAX_ATTEMPTS);

                return response()->json([
                    'error' => 'Code invalide',
                    'attempts_remaining' => max(0, self::OTP_MAX_ATTEMPTS - $attempts),
                ], 400);
            }

            // Succès : déverrouillage 10 minutes + reset du compteur.
            Cache::put("template_edit_unlocked_{$adminId}", time(), now()->addSeconds(self::UNLOCK_TTL));
            Cache::forget($attemptsKey);
            OtpService::clear($adminId, 'admin');
            AuditLogger::log('TEMPLATE_OTP_VERIFIED', $adminId, 'admin', 'Édition modèle déverrouillée 10 min');

            return response()->json(['unlocked' => true, 'expires_in' => self::UNLOCK_TTL]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Erreur interne'], 500);
        }
    }

    // =========================================================
    // POST /api/v1/admin/template/update — exige OTP < 10 min (L012)
    // =========================================================
    public function updateTemplate(TemplateUpdateRequest $request): JsonResponse
    {
        try {
            $adminId = $this->adminId($request);

            if (! $this->isUnlocked($adminId)) {
                AuditLogger::log('TEMPLATE_UPDATE_BLOCKED', $adminId, 'admin', 'Tentative sans OTP valide');

                return response()->json(['error' => 'OTP requis'], 403);
            }

            $content = (string) $request->validated('content');
            if (strlen(trim($content)) < 50) {
                return response()->json(['error' => 'Contenu de template invalide'], 400);
            }

            $current = DB::table('contract_templates')->where('name', 'contract')->first();
            if (! $current) {
                return response()->json(['error' => 'Modèle de contrat introuvable'], 404);
            }

            DB::table('contract_templates')->where('name', 'contract')
                ->update(['content' => $content, 'updated_at' => now()]);

            DB::table('contract_history')->insert([
                'template_id' => (int) $current->id,
                'old_content' => (string) $current->content,
                'changed_at' => now()->format('Y-m-d H:i:s'),
            ]);

            AuditLogger::log('UPDATE_CONTRACT_TEMPLATE', $adminId, 'admin', 'Template ID: '.$current->id);

            // L012 : après succès, on referme la porte — l'OTP a été consommé.
            OtpService::clear($adminId ?? 0, 'admin');
            Cache::forget("template_edit_unlocked_{$adminId}");
            Cache::forget("template_otp_attempts_{$adminId}");

            return response()->json(['message' => 'Template mis à jour']);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Erreur interne'], 500);
        }
    }

    // =========================================================
    // POST /api/v1/admin/ticket/reply — réponse admin au ticket (W-13)
    // =========================================================
    public function ticketReply(TicketReplyRequest $request): JsonResponse
    {
        try {
            $ticketId = (int) $request->validated('ticket_id');
            $reponse = trim((string) $request->validated('reponse'));

            $updated = DB::table('tickets_support')->where('id', $ticketId)->update([
                'reponse' => $reponse,
                'reponse_at' => now()->format('Y-m-d H:i:s'),
                'statut' => 'répondu',
            ]);

            if ($updated === 0) {
                return response()->json(['error' => 'Ticket introuvable'], 404);
            }

            // Notification catalogue : ticket_reponse (best-effort, jamais fatal)
            $ticket = DB::table('tickets_support')->where('id', $ticketId)->first();
            if ($ticket && $ticket->client_id) {
                $this->push->notifyEvent(
                    (int) $ticket->client_id,
                    'ticket_reponse',
                    'Réponse à votre ticket',
                    'Notre équipe a répondu à votre demande de support.',
                    '/espace-client?tab=support'
                );
            }

            return response()->json(['message' => 'Réponse enregistrée']);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Erreur interne'], 500);
        }
    }

    // =========================================================
    // Helpers — sessions PHP → cache/DB (stateless Sanctum)
    // =========================================================

    /** Client admin lié au token Sanctum (parité $_SESSION['admin_id']). */
    private function adminId(Request $request): ?int
    {
        $user = $request->user();
        if (! $user) {
            return null;
        }
        $client = DB::table('clients')->where('email', $user->email)->first();

        return $client ? (int) $client->id : null;
    }

    private function adminEmail(Request $request): string
    {
        return (string) ($request->user()?->email ?? '');
    }

    /** Déverrouillage actif (< 10 min) — parité $_SESSION['template_edit_unlocked']. */
    private function isUnlocked(?int $adminId): bool
    {
        if ($adminId === null) {
            return false;
        }
        $unlockedAt = (int) Cache::get("template_edit_unlocked_{$adminId}", 0);

        return $unlockedAt > 0 && (time() - $unlockedAt) <= self::UNLOCK_TTL;
    }
}
