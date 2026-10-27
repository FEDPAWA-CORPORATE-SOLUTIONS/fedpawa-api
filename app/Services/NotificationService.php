<?php

namespace App\Services;

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

    private function isConfigured(): bool
    {
        $host = (string) config('mail.host', '');
        $mailer = (string) config('mail.default', '');

        return $mailer === 'smtp'
            && $host !== ''
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
            'Votre code de signature de contrat est : <strong>'.htmlspecialchars($code)."</strong><br>Si vous n'êtes pas à l'origine de cette demande, ignorez cet e-mail."
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
