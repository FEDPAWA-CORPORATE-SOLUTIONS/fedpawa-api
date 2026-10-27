<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ContactStoreRequest;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Parité de src/Controller/ContactController.php :
 * - POST /api/v1/contact : public anonyme, honeypot « site » ;
 * - mêmes validations/messages ('Nom invalide', 'E-mail invalide',
 *   'Téléphone invalide', 'Sujet requis', 'Message requis (5000 caractères max)') ;
 * - insertion contact_messages (statut 'nouveau', date_reception now) ;
 * - notification admin best-effort (jamais bloquante).
 *
 * Tables : contact_messages (BE-SCHEMA / edge migration).
 */
class ContactController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    /** POST /api/v1/contact */
    public function store(ContactStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $nom = trim($data['nom']);
        $email = trim($data['email']);
        $telephone = trim($data['telephone'] ?? '');
        $sujet = trim($data['sujet']);
        $message = trim($data['message']);

        try {
            // Anti-spam honeypot : même réponse de succès, aucune insertion.
            // Parité source !empty($site) : en PHP empty(' ') === false,
            // donc TOUT site non vide (y compris espaces seuls) est refusé
            // silencieusement. TrimStrings exclut 'site' (bootstrap) pour
            // que les espaces arrivent intacts jusqu'ici.
            if (($data['site'] ?? '') !== '') {
                return response()->json(['message' => 'Merci ! Votre message a bien été envoyé.']);
            }

            DB::table('contact_messages')->insert([
                'nom' => $nom,
                'email' => $email,
                'telephone' => $telephone,
                'sujet' => $sujet,
                'message' => $message,
                'statut' => 'nouveau',
                'date_reception' => now()->format('Y-m-d H:i:s'),
            ]);

            // Notification admin (best effort, parité try/catch legacy)
            try {
                $this->notifications->sendContactNotification(
                    config('fedpawa.contact_email', 'contact@fedpawa.com'),
                    $sujet, $nom, $email, $telephone, $message
                );
            } catch (\Throwable $mailEx) {
                Log::error('Contact mail échoué: '.$mailEx->getMessage());
            }

            return response()->json([
                'message' => 'Merci ! Votre message a bien été envoyé. Nous vous répondrons sous 24h ouvrées.',
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Erreur interne'], 500);
        }
    }
}
