<?php

namespace App\Mail;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;

/**
 * Transport mailer custom « resend » — ZÉRO dépendance composer ajoutée.
 *
 * RESEND-INTEGRATION.md §3 (Option A) : l'API Resend est un simple POST JSON
 * (https://api.resend.com/emails), le HTTP client Laravel suffit. Le code
 * existant (Mail::send / Mail::to()->send(...)) fonctionne sans modification ;
 * le transport ne s'active QUE si MAIL_MAILER=resend (jour J, clé posée).
 *
 * En test : MAIL_MAILER=log inchangé → ce transport n'est jamais appelé.
 */
class ResendTransport extends AbstractTransport
{
    protected function doSend(SentMessage $message): void
    {
        $email = $message->getOriginalMessage();
        if (! $email instanceof Email) {
            throw new \LogicException('ResendTransport attend un Symfony\Component\Mime\Email');
        }

        $key = (string) config('services.resend.key', '');
        if ($key === '') {
            // Jamais de valeur de clé dans les logs — présence seule.
            Log::warning('ResendTransport: RESEND_API_KEY non posée — envoi impossible (attendu 401 côté Resend)');
            throw new TransportException('RESEND_API_KEY non configurée', 401);
        }

        $from = $email->getFrom()[0] ?? null; // Address: MAIL_FROM_NAME <MAIL_FROM_ADDRESS>
        $payload = [
            'from' => $from
                ? ($from->getName() !== ''
                    ? $from->getName().' <'.$from->getAddress().'>'
                    : $from->getAddress())
                : null,
            'to' => array_map(fn ($a) => $a->getAddress(), $email->getTo()),
            'subject' => $email->getSubject(),
            'html' => $email->getHtmlBody(),
        ];
        if ($email->getTextBody() !== null) {
            $payload['text'] = $email->getTextBody();
        }
        $replyTo = $email->getReplyTo() ?: ($from ? [$from] : []);
        if ($replyTo !== []) {
            $payload['reply_to'] = array_map(fn ($a) => $a->getAddress(), $replyTo);
        }
        if ($email->getCc()) {
            $payload['cc'] = array_map(fn ($a) => $a->getAddress(), $email->getCc());
        }
        if ($email->getBcc()) {
            $payload['bcc'] = array_map(fn ($a) => $a->getAddress(), $email->getBcc());
        }

        $response = Http::timeout(10)
            ->withHeaders([
                'Authorization' => 'Bearer '.$key,
                'User-Agent' => 'fedpawa-api/1.0', // obligatoire sinon 403 (erreur 1010)
            ])
            ->post('https://api.resend.com/emails', $payload);

        if ($response->successful()) {
            $id = $response->json('id');
            Log::info("Resend OK id={$id}"); // id Resend uniquement, jamais de secret

            return;
        }

        // 429 rate limit (10 req/s) -> exception retryable avec Retry-After ;
        // 429 quota quotidien/mensuel -> on abandonne (ne PAS réessayer avant minuit UTC).
        if ($response->status() === 429 && ($response->json('name') ?? '') === 'rate_limit_exceeded') {
            throw new TransportException(
                'Resend 429 rate_limit_exceeded, retry-after='.$response->header('Retry-After'), 429
            );
        }
        Log::warning('Resend échec non retryable: HTTP '.$response->status().' '.$response->body());
        throw new TransportException(
            'Resend HTTP '.$response->status().' : '.$response->json('message', $response->body()), $response->status()
        );
    }

    public function __toString(): string
    {
        return 'resend';
    }
}
