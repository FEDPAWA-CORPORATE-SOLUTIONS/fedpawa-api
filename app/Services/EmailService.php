<?php

namespace App\Services;

use App\Mail\EventMail;
use App\Models\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * EmailService — envoi des e-mails transactionnels du catalogue
 * (BRIEF-B3 / CATALOGUE-EVENEMENTS.md).
 *
 * Contrat identique à PushService::notifyEvent() : ne lève JAMAIS d'exception,
 * retourne un tableau de résultat [evenement, envoye, motif, sujet] et logue
 * proprement (aucun secret, aucune clé, aucun token dans les logs).
 *
 * Gating (décision propriétaire, CATALOGUE §1) :
 * - 'standard'  : e-mail envoyé UNIQUEMENT si kyc.statuts === 'approuvé' (dernier
 *   dossier KYC) ET clients.email_verified_at non null ;
 * - 'securite'  : EXCEPTION — e-mails de sécurité (nouvelle connexion, mot de passe
 *   modifié) envoyés quel que soit le KYC, mais toujours à l'adresse du compte
 *   (e-mail vérifié requis) ;
 * - 'bienvenue' : EXCEPTION (décision orchestrateur, réversible, recommandation de D
 *   notée telle quelle) — l'e-mail « inscription_bienvenue » part vers tout client
 *   e-mail vérifié SANS exigence KYC ;
 * - 'aucune'    : EXCEPTION — l'e-mail de vérification lui-même part même non
 *   vérifié (c'est lui qui permet de vérifier). Point d'envoi UNIQUE :
 *   NotificationService::sendVerificationEmail, pas de duplication.
 *
 * Garde-fou free tier Resend (100 e-mails/jour, jour calendaire UTC) :
 * compteur quotidien en cache fichier + limite configurable EMAIL_DAILY_LIMIT
 * (défaut 100, placeholder commenté dans .env) ; au-delà → skip avec le motif
 * tracé `limite_quotidienne` (l'événement in-app/push reste, parité RESEND §2.4).
 *
 * Dédup : (client_id, evenement, jour UTC) en cache — un même événement rejoué
 * (ex. webhook Campay rejoué, déjà idempotent côté paiement) ne renvoie pas
 * 2 e-mails. Compteur incrémenté UNIQUEMENT après un envoi réussi.
 */
class EmailService
{
    /** Gabarit + sujet français + gating par événement (sujets = <title> des templates de D). */
    public const EVENTS = [
        'verification_email' => [
            'view' => 'emails.01-verification-email',
            'subject' => 'Vérifiez votre adresse e-mail — FEDPAWA',
            'gating' => 'aucune',
        ],
        'inscription_bienvenue' => [
            'view' => 'emails.02-inscription-bienvenue',
            'subject' => 'Bienvenue chez FEDPAWA, {{client_nom}} — votre compte est actif',
            'gating' => 'bienvenue',
        ],
        'souscription_confirmee' => [
            'view' => 'emails.03-souscription-confirmee',
            'subject' => 'Votre souscription {{pack}} est confirmée — FEDPAWA',
            'gating' => 'standard',
        ],
        'paiement_recu' => [
            'view' => 'emails.04-paiement-recu',
            'subject' => 'Paiement reçu : {{montant}} {{devise}} — FEDPAWA',
            'gating' => 'standard',
        ],
        'facture_emise' => [
            'view' => 'emails.05-facture-emise',
            'subject' => 'Votre facture {{numero_facture}} est disponible — FEDPAWA',
            'gating' => 'standard',
        ],
        'contrat_signe' => [
            'view' => 'emails.06-contrat-signe',
            'subject' => 'Votre contrat est signé — dossier #{{dossier_id}} — FEDPAWA',
            'gating' => 'standard',
        ],
        'contrat_scelle' => [
            'view' => 'emails.07-contrat-scelle',
            'subject' => 'Votre contrat est scellé — dossier #{{dossier_id}} — FEDPAWA',
            'gating' => 'standard',
        ],
        'dossier_kyc_approuve' => [
            'view' => 'emails.08a-dossier-kyc-approuve',
            'subject' => 'Vos documents KYC sont validés — FEDPAWA',
            'gating' => 'standard',
        ],
        'dossier_kyc_rejete' => [
            'view' => 'emails.08b-dossier-kyc-rejete',
            'subject' => 'Documents KYC à corriger — FEDPAWA',
            'gating' => 'standard',
        ],
        'dossier_kyc_a_completer' => [
            'view' => 'emails.08c-dossier-kyc-a-completer',
            'subject' => 'Votre dossier KYC est incomplet — FEDPAWA',
            'gating' => 'standard',
        ],
        'ticket_reponse' => [
            'view' => 'emails.09-ticket-reponse',
            'subject' => 'Réponse à votre ticket #{{ticket_id}} — FEDPAWA',
            'gating' => 'standard',
        ],
        'courrier_recu' => [
            'view' => 'emails.10-courrier-recu',
            'subject' => 'Nouveau courrier reçu — FEDPAWA',
            'gating' => 'standard',
        ],
        'securite_nouvelle_connexion' => [
            'view' => 'emails.11-securite-nouvelle-connexion',
            'subject' => 'Nouvelle connexion à votre compte FEDPAWA — vérifiez qu\'il s\'agit bien de vous',
            'gating' => 'securite',
        ],
        'securite_mot_de_passe' => [
            'view' => 'emails.12-securite-mot-de-passe',
            'subject' => 'Votre mot de passe FEDPAWA a été modifié',
            'gating' => 'securite',
        ],
        'abonnement_active' => [
            'view' => 'emails.13-abonnement-active',
            'subject' => 'Votre abonnement « {{pack}} » est actif — FEDPAWA',
            'gating' => 'standard',
        ],
        'abonnement_renouvellement' => [
            'view' => 'emails.14-abonnement-renouvellement',
            'subject' => 'Votre abonnement « {{pack}} » arrive à échéance le {{date_renouvellement}} — FEDPAWA',
            'gating' => 'standard',
        ],
    ];

    /**
     * Envoie l'e-mail d'un événement à un client. Ne lève jamais d'exception.
     *
     * @param  array<string,mixed>  $donnees  variables du gabarit ({{snake_case}})
     * @return array{evenement: string, envoye: bool, motif: ?string, sujet: ?string}
     */
    public function envoyer(Client $client, string $evenement, array $donnees = []): array
    {
        $result = ['evenement' => $evenement, 'envoye' => false, 'motif' => null, 'sujet' => null];

        if (! isset(self::EVENTS[$evenement])) {
            $result['motif'] = 'evenement_inconnu';
            Log::warning("EmailService — événement inconnu ignoré : {$evenement}");

            return $result;
        }
        $def = self::EVENTS[$evenement];

        try {
            // --- Gating e-mail vérifié (tous les gating sauf 'aucune') ---
            if ($def['gating'] !== 'aucune' && $client->email_verified_at === null) {
                $result['motif'] = 'email_non_verifie';
                Log::info("EmailService — e-mail {$evenement} SAUTÉ (e-mail non vérifié) client #{$client->id}");

                return $result;
            }

            // --- Gating KYC approuvé (gating 'standard' uniquement) ---
            if ($def['gating'] === 'standard') {
                // même lecture que AuthController::me() : statut du DERNIER dossier KYC
                $kycStatut = DB::table('kyc')->where('client_id', (int) $client->id)->orderByDesc('id')->value('statuts');
                if ($kycStatut !== 'approuvé') {
                    $result['motif'] = 'kyc_non_approuve';
                    Log::info("EmailService — e-mail {$evenement} SAUTÉ (KYC non approuvé, statut=".($kycStatut ?? 'null').") client #{$client->id}");

                    return $result;
                }
            }

            // --- Garde-fou free tier : 100 e-mails/jour (jour calendaire UTC) ---
            $limite = (int) config('mail.daily_limit', 100);
            $jour = now()->format('Y-m-d');
            $store = Cache::store('file'); // cache fichier : partagé entre processus (artisan + web)
            $cleCompteur = "emails:envoyes:{$jour}";
            $envoyes = (int) $store->get($cleCompteur, 0);
            if ($envoyes >= $limite) {
                $result['motif'] = 'limite_quotidienne';
                Log::info("EmailService — e-mail {$evenement} SAUTÉ (limite_quotidienne {$envoyes}/{$limite} le {$jour}) client #{$client->id}");

                return $result;
            }

            // --- Dédup (client, événement, jour) ---
            $cleDedup = "email:dedup:{$client->id}:{$evenement}:{$jour}";
            if ($store->get($cleDedup)) {
                $result['motif'] = 'doublon_jour';
                Log::info("EmailService — e-mail {$evenement} SAUTÉ (doublon_jour) client #{$client->id}");

                return $result;
            }

            // --- Variables communes + sujet rendu ---
            $data = array_merge([
                'client_nom' => (string) $client->nom,
                'client_email' => (string) $client->email,
                'annee' => now()->format('Y'),
                'devise' => 'XAF',
                'lien_espace_client' => 'https://app.fedpawa.com/espace-client',
            ], $donnees);

            $sujet = preg_replace_callback(
                '/\{\{([a-zA-Z_0-9]+)\}\}/',
                fn ($m) => (string) ($data[$m[1]] ?? $m[0]),
                $def['subject']
            );
            $result['sujet'] = $sujet;

            // --- Envoi via le mailer par défaut (log en test, resend le jour J) ---
            Mail::to($client->email)->send(new EventMail($evenement, $sujet, $def['view'], $data));

            $store->put($cleCompteur, $envoyes + 1, now()->addDays(2));
            $store->put($cleDedup, 1, now()->addDays(2));

            $result['envoye'] = true;
            Log::info("EmailService — e-mail {$evenement} envoyé à client #{$client->id} (compteur {$envoyes}/{$limite} le {$jour})");
        } catch (\Throwable $e) {
            // Jamais d'exception remontée ; aucun secret dans le log (message d'erreur générique).
            $result['motif'] = 'erreur_envoi';
            Log::warning("EmailService — échec envoi {$evenement} client #{$client->id} (non fatal) : ".$e->getMessage());
        }

        return $result;
    }

    /** Compteur du jour (jour UTC) — utilisé par les preuves et l'ops. */
    public function compteurJour(?string $jour = null): int
    {
        return (int) Cache::store('file')->get('emails:envoyes:'.($jour ?? now()->format('Y-m-d')), 0);
    }

    /** Limite configurée (EMAIL_DAILY_LIMIT, défaut 100). */
    public function limiteJour(): int
    {
        return (int) config('mail.daily_limit', 100);
    }
}
