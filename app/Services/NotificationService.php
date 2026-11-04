<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Parité de src/Service/NotificationService.php : SMTP non configuré
 * => pas d'envoi (dégradation gracieuse, parité isConfigured()).
 * En Laravel : MAIL_MAILER smtp + host réel requis, sinon log silencieux.
 *
 * Push PWA : sendRenewalReminder crée aussi l'événement in-app
 * (type abonnement « Renouvellement proche ») — ancrage du cron legacy.
 */
class NotificationService
{
    public function __construct(private PushService $push) {}

    /**
     * Le transport e-mail est-il réellement configuré (SMTP avec un hôte
     * réel) ? Appelé par les appelants pour DIRE explicitement quand un
     * envoi n'est pas possible, au lieu de faire croire à un envoi.
     */
    public function transportConfigure(): bool
    {
        return $this->isConfigured();
    }

    private function isConfigured(): bool
    {
        $mailer = (string) config('mail.default', '');
        // Laravel 11+ : l'hôte vit dans mail.mailers.smtp.host (pas mail.host)
        $host = (string) (config('mail.mailers.smtp.host') ?? config('mail.host', ''));
        $port = (int) (config('mail.mailers.smtp.port') ?? 0);

        return $mailer === 'resend'
            || ($mailer === 'smtp' && $host !== '' && $port > 0)
            && ! str_contains($host, 'example.com')
            && ! str_contains($host, 'localhost');
    }

    private function send(string $email, string $subject, string $htmlBody): bool
    {
        if (! $this->isConfigured()) {
            \Log::info("NotificationService: SMTP non configuré — e-mail non envoyé à {$email}");

            return false;
        }
        try {
            Mail::send([], [], function ($message) use ($email, $subject, $htmlBody) {
                $message->to($email)->subject($subject)->html($htmlBody);
            });

            return true;
        } catch (\Throwable $e) {
            \Log::warning("Message could not be sent. Mailer Error: {$e->getMessage()}");

            return false;
        }
    }

    public function sendRenewalReminder(string $email, string $clientName): bool
    {
        // Push PWA : ancrage cron legacy — événement in-app type abonnement.
        // Best-effort : e-mail sans client connu ne crée rien.
        $client = DB::table('clients')->where('email', $email)->first();
        if ($client) {
            $this->push->sendToClient(
                (int) $client->id,
                'abonnement',
                'Renouvellement proche',
                'Bonjour '.htmlspecialchars($clientName).', votre abonnement expire bientôt. Pensez à le renouveler.',
                '/espace-client?tab=abonnements'
            );
        }

        return $this->send(
            $email,
            'Rappel: Votre abonnement',
            'Bonjour <strong>'.htmlspecialchars($clientName).'</strong>,<br>Votre abonnement expire bientôt.'
        );
    }

    public function sendWelcomeEmail(string $email, string $clientName): bool
    {
        return $this->send(
            $email,
            'Bienvenue chez FEDPAWA',
            'Bonjour <strong>'.htmlspecialchars($clientName).'</strong>,<br>Bienvenue chez FEDPAWA Corporate Solutions. Votre souscription est en cours de finalisation.'
        );
    }

    /**
     * E-mail de vérification d'adresse (CONTRAT-VERIF.md).
     *
     * Envoi via le mailer PAR DÉFAUT (Mail::send) et non via send()/isConfigured()
     * : ainsi le même code fonctionne avec MAIL_MAILER=log (test : l'e-mail complet,
     * lien compris, atterrit dans storage/logs/laravel.log) et avec MAIL_MAILER=smtp
     * (prod : Brevo, activé par l'orchestrateur SANS changement de code).
     *
     * @param  string  $url  URL complète du contrat : https://app.fedpawa.com/verification-email?token=<token>
     */
    public function sendVerificationEmail(Client $client, string $url): bool
    {
        $subject = 'Vérifiez votre adresse e-mail — FEDPAWA';
        $lien = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $htmlBody = 'Bonjour <strong>'.htmlspecialchars((string) $client->nom, ENT_QUOTES, 'UTF-8').'</strong>,<br><br>'
            .'Merci de confirmer votre adresse e-mail en cliquant sur le lien suivant '
            .'(valable '.Client::VERIFICATION_TTL_HEURES.' heures) :<br><br>'
            .'<a href="'.$lien.'">'.$lien.'</a><br><br>'
            ."Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :<br>".$lien.'<br><br>'
            ."Si vous n'êtes pas à l'origine de cette inscription, ignorez cet e-mail.<br><br>"
            .'FEDPAWA Corporate Solutions';

        try {
            Mail::send([], [], function ($message) use ($client, $subject, $htmlBody) {
                $message->to($client->email)->subject($subject)->html($htmlBody);
            });

            return true;
        } catch (\Throwable $e) {
            \Log::warning("sendVerificationEmail échec pour {$client->email} : ".$e->getMessage());

            return false;
        }
    }

    public function sendOtp(string $email, string $code): bool
    {
        return $this->send(
            $email,
            'Votre code de réinitialisation FEDPAWA',
            'Votre code de réinitialisation est : <strong>'.htmlspecialchars($code)."</strong><br>Il expire dans 15 minutes. Si n'êtes pas à l'origine de cette demande, ignorez cet e-mail."
        );
    }

    public function sendSignatureOtp(string $email, string $code): bool
    {
        return $this->send(
            $email,
            'Votre code de signature FEDPAWA',
            'Votre code de signature de contrat est : <strong>'.htmlspecialchars($code)."</strong><br>Il expire dans 10 minutes. Si vous n'êtes pas à l'origine de cette demande, ignorez cet e-mail."
        );
    }

    /**
     * Parité src/Service/NotificationService.php::sendContactNotification :
     * mail admin « Nouveau message de contact — <sujet> » avec nom, e-mail,
     * téléphone et corps du message (échappés + nl2br).
     */
    public function sendContactNotification(string $dest, string $sujet, string $nom, string $email, string $telephone, string $message): bool
    {
        return $this->send(
            $dest,
            'Nouveau message de contact — '.$sujet,
            'Nom : '.htmlspecialchars($nom).'<br>E-mail : '.htmlspecialchars($email).
            '<br>Téléphone : '.htmlspecialchars($telephone).
            '<br><br>'.nl2br(htmlspecialchars($message))
        );
    }
}
