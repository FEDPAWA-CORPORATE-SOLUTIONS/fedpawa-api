<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MailRecu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
