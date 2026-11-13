<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MailEnvoye;
use App\Models\MailRecu;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\EventMail;

/**
 * CONTRAT-MAIL.md §2 — API admin de la boîte de réception (R3-A) :
 * routes sous /api/v1/admin/mails, garde auth:sanctum + CheckAdminRole
 * (401 non authentifié, 403 non admin).
 *
 * - GET  /admin/mails?lu=&recherche=&page=&par_page=  → liste antichronologique paginée + meta
 * - GET  /admin/mails/count                          → {total, non_lus} (badge)
 * - GET  /admin/mails/{id}                           → détail (corps texte + HTML, pièces jointes)
 * - POST /admin/mails/{id}/lu                        → marquer comme lu
 * - POST /admin/mails/tout-lu                        → tout marquer comme lu
 */
class AdminMailController extends Controller
{
    public function __construct(private NotificationService $notifier) {}
    /** Ressource liste (sans les gros corps). */
    private function resourceResume(MailRecu $m): array
    {
        return [
            'id' => $m->id,
            'resend_email_id' => $m->resend_email_id,
            'expediteur' => $m->expediteur,
            'destinataires' => $m->destinataires,
            'sujet' => $m->sujet,
            'recu_le' => $m->recu_le?->toIso8601String(),
            'lu' => (bool) $m->lu,
            'nb_pieces_jointes' => count($m->pieces_jointes ?? []),
        ];
    }

    /** GET /api/v1/admin/mails — liste antichronologique paginée + filtres. */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'lu' => ['nullable', 'boolean'],
            'recherche' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'par_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = MailRecu::orderByDesc('recu_le')->orderByDesc('id');
        if ($request->has('lu') && $request->query('lu') !== null && $request->query('lu') !== '') {
            $query->where('lu', filter_var($request->query('lu'), FILTER_VALIDATE_BOOLEAN));
        }
        if ($recherche = trim((string) $request->query('recherche', ''))) {
            $query->where(function ($q) use ($recherche) {
                $q->where('expediteur', 'like', '%'.$recherche.'%')
                    ->orWhere('sujet', 'like', '%'.$recherche.'%');
            });
        }

        $parPage = (int) $request->query('par_page', 20);
        $paginator = $query->paginate($parPage, ['*'], 'page', max(1, (int) $request->query('page', 1)));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (MailRecu $m) => $this->resourceResume($m))->all(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'par_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'derniere_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /** GET /api/v1/admin/mails/count — badge {total, non_lus}. */
    public function count(): JsonResponse
    {
        return response()->json([
            'total' => MailRecu::count(),
            'non_lus' => MailRecu::where('lu', false)->count(),
        ]);
    }

    /** GET /api/v1/admin/mails/{id} — détail complet. */
    public function show(int $id): JsonResponse
    {
        $mail = MailRecu::find($id);
        if (! $mail) {
            return response()->json(['error' => 'Mail introuvable'], 404);
        }

        return response()->json([
            'mail' => array_merge($this->resourceResume($mail), [
                'copie' => $mail->copie,
                'corps_texte' => $mail->corps_texte,
                'corps_html' => $mail->corps_html,
                'pieces_jointes' => $mail->pieces_jointes,
                'message_id' => $mail->message_id,
                'cree_le' => $mail->created_at?->toIso8601String(),
                'modifie_le' => $mail->updated_at?->toIso8601String(),
            ]),
        ]);
    }

    /** POST /api/v1/admin/mails/{id}/lu — marquer comme lu. */
    public function marquerLu(int $id): JsonResponse
    {
        $mail = MailRecu::find($id);
        if (! $mail) {
            return response()->json(['error' => 'Mail introuvable'], 404);
        }
        $mail->lu = true;
        $mail->save();

        return response()->json(['mail' => $this->resourceResume($mail)]);
    }

    /** POST /api/v1/admin/mails/tout-lu — tout marquer comme lu. */
    public function toutLu(): JsonResponse
    {
        $n = MailRecu::where('lu', false)->update(['lu' => true]);

        return response()->json(['marques_lus' => $n]);
    }

    // =========================================================
    // ENVOI DE NOUVEAUX E-MAILS DEPUIS L'ADMIN
    // =========================================================

    /** POST /api/v1/admin/mails — envoyer un nouveau mail (admin). */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'destinataire' => ['required', 'email', 'max:255'],
            'copie' => ['nullable', 'array'],
            'copie.*' => ['email', 'max:255'],
            'sujet' => ['required', 'string', 'max:255'],
            'corps_html' => ['nullable', 'string'],
            'corps_texte' => ['nullable', 'string'],
        ]);

        $expediteur = config('mail.from.address', 'contact@fedpawa.com');
        $nomExpediteur = config('mail.from.name', 'FEDPAWA');

        $mailEnvoye = MailEnvoye::create([
            'expediteur' => $expediteur,
            'destinataire' => $request->string('destinataire'),
            'copie' => $request->validated('copie') ?? [],
            'sujet' => $request->string('sujet'),
            'corps_texte' => $request->string('corps_texte'),
            'corps_html' => $request->string('corps_html'),
            'statut' => 'en_attente',
        ]);

        try {
            $sujetComplet = $request->string('sujet');
            $html = $request->string('corps_html') ?? nl2br(e($request->string('corps_texte') ?? ''));
            $texte = $request->string('corps_texte') ?? strip_tags($request->string('corps_html') ?? '');

            Mail::to($request->string('destinataire'))
                ->cc($request->validated('copie') ?? [])
                ->send(new EventMail(
                    'admin_courrier_sortant',
                    $sujetComplet,
                    'emails.admin_courrier_sortant',
                    [
                        'expediteur' => $expediteur,
                        'nom_expediteur' => $nomExpediteur,
                        'destinataire' => $request->string('destinataire'),
                        'sujet' => $sujetComplet,
                        'corps_html' => $html,
                        'corps_texte' => $texte,
                        'date_envoi' => now()->format('d/m/Y H:i'),
                        'lien_espace_client' => config('app.url', 'https://app.fedpawa.com').'/admin/mails',
                    ]
                ));

            $mailEnvoye->update([
                'statut' => 'envoye',
                'envoye_le' => now(),
            ]);

            AuditLogger::log('ADMIN_MAIL_SENT', $mailEnvoye->id, 'admin', 'À: '.$request->string('destinataire'));

            return response()->json([
                'message' => 'E-mail envoyé avec succès',
                'mail' => [
                    'id' => $mailEnvoye->id,
                    'destinataire' => $mailEnvoye->destinataire,
                    'sujet' => $mailEnvoye->sujet,
                    'statut' => 'envoye',
                    'envoye_le' => $mailEnvoye->envoye_le?->toIso8601String(),
                ],
            ], 201);
        } catch (\Throwable $e) {
            $mailEnvoye->update([
                'statut' => 'echec',
                'erreur' => $e->getMessage(),
            ]);

            Log::warning('AdminMailController: échec envoi mail vers '.$request->string('destinataire').' : '.$e->getMessage());

            return response()->json([
                'error' => 'Échec de l\'envoi de l\'e-mail',
                'detail' => config('app.debug') ? $e->getMessage() : 'Vérifiez la configuration SMTP.',
            ], 500);
        }
    }

    /** POST /api/v1/admin/mails/{id}/replier — répondre à un mail reçu. */
    public function reply(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'corps_html' => ['nullable', 'string'],
            'corps_texte' => ['nullable', 'string'],
            'sujet' => ['sometimes', 'string', 'max:255'],
        ]);

        $mailRecu = MailRecu::find($id);
        if (! $mailRecu) {
            return response()->json(['error' => 'Mail introuvable'], 404);
        }

        $expediteur = config('mail.from.address', 'contact@fedpawa.com');
        $sujetReponse = $request->string('sujet')
            ?? ('Re: '.$mailRecu->sujet);

        $destinataire = is_array($mailRecu->expediteur) ? ($mailRecu->expediteur[0] ?? '') : $mailRecu->expediteur;
        // extraire l'adresse email si format "Nom <email>"
        if (preg_match('/<([^>]+)>/', $destinataire, $m)) {
            $destinataire = $m[1];
        }

        $mailEnvoye = MailEnvoye::create([
            'expediteur' => $expediteur,
            'destinataire' => $destinataire,
            'copie' => [],
            'sujet' => $sujetReponse,
            'corps_texte' => $request->string('corps_texte'),
            'corps_html' => $request->string('corps_html'),
            'statut' => 'en_attente',
        ]);

        try {
            $html = $request->string('corps_html') ?? nl2br(e($request->string('corps_texte') ?? ''));
            $texte = $request->string('corps_texte') ?? strip_tags($request->string('corps_html') ?? '');

            Mail::to($destinataire)
                ->send(new EventMail(
                    'admin_reponse_courrier',
                    $sujetReponse,
                    'emails.admin_reponse_courrier',
                    [
                        'expediteur' => $expediteur,
                        'destinataire' => $destinataire,
                        'sujet_original' => $mailRecu->sujet,
                        'sujet' => $sujetReponse,
                        'corps_html' => $html,
                        'corps_texte' => $texte,
                        'date_reception_originale' => $mailRecu->recu_le?->format('d/m/Y H:i'),
                        'date_envoi' => now()->format('d/m/Y H:i'),
                        'lien_espace_client' => config('app.url', 'https://app.fedpawa.com').'/admin/mails',
                    ]
                ));

            $mailEnvoye->update([
                'statut' => 'envoye',
                'envoye_le' => now(),
            ]);

            AuditLogger::log('ADMIN_MAIL_REPLIED', $mailRecu->id, 'admin', 'Réponse à mail #'.$id.' vers '.$destinataire);

            return response()->json([
                'message' => 'Réponse envoyée avec succès',
                'mail' => [
                    'id' => $mailEnvoye->id,
                    'destinataire' => $mailEnvoye->destinataire,
                    'sujet' => $mailEnvoye->sujet,
                    'statut' => 'envoye',
                    'envoye_le' => $mailEnvoye->envoye_le?->toIso8601String(),
                ],
            ], 201);
        } catch (\Throwable $e) {
            $mailEnvoye->update([
                'statut' => 'echec',
                'erreur' => $e->getMessage(),
            ]);

            Log::warning('AdminMailController: échec réponse mail #'.$id.' : '.$e->getMessage());

            return response()->json([
                'error' => 'Échec de l\'envoi de la réponse',
                'detail' => config('app.debug') ? $e->getMessage() : 'Vérifiez la configuration SMTP.',
            ], 500);
        }
    }

    /** GET /api/v1/admin/mails/envoyes — liste des e-mails envoyés par l'admin. */
    public function envoyes(Request $request): JsonResponse
    {
        $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'par_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'recherche' => ['nullable', 'string', 'max:200'],
            'statut' => ['nullable', 'string', 'in:en_attente,envoye,echec'],
        ]);

        $query = MailEnvoye::orderByDesc('created_at');
        if ($recherche = trim((string) $request->query('recherche', ''))) {
            $query->where(function ($q) use ($recherche) {
                $q->where('destinataire', 'like', '%'.$recherche.'%')
                    ->orWhere('sujet', 'like', '%'.$recherche.'%');
            });
        }
        if ($statut = $request->query('statut')) {
            $query->where('statut', $statut);
        }

        $parPage = (int) $request->query('par_page', 20);
        $paginator = $query->paginate($parPage, ['*'], 'page', max(1, (int) $request->query('page', 1)));

        return response()->json([
            'data' => collect($paginator->items())->map(function (MailEnvoye $m) {
                return [
                    'id' => $m->id,
                    'expediteur' => $m->expediteur,
                    'destinataire' => $m->destinataire,
                    'copie' => $m->copie,
                    'sujet' => $m->sujet,
                    'statut' => $m->statut,
                    'est_envoye' => $m->estEnvoye,
                    'est_echec' => $m->estEchec,
                    'erreur' => $m->erreur,
                    'envoye_le' => $m->envoye_le?->toIso8601String(),
                    'cree_le' => $m->created_at?->toIso8601String(),
                ];
            })->all(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'par_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'derniere_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
