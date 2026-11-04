<?php

namespace App\Services;

use App\Models\MailRecu;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * CONTRAT-MAIL.md §2 — réception Resend :
 * - vérification de signature Svix (en-têtes svix-id / svix-timestamp / svix-signature,
 *   secret RESEND_WEBHOOK_SECRET au format whsec_...) sur le CORPS BRUT ;
 * - récupération du corps complet via GET {api_base}/emails/receiving/{email_id}
 *   (le webhook ne porte que les métadonnées) + pièces jointes via
 *   GET {api_base}/emails/receiving/{email_id}/attachments/{attachment_id} ;
 * - assainissement du HTML (scripts, iframes, objets embarqués, on*=, javascript:) ;
 * - enregistrement idempotent dans mails_recus (unique resend_email_id).
 *
 * RÈGLE ABSOLUE : la clé API et le secret de signature ne sont JAMAIS logués.
 */
class ResendInboundService
{
    /** Tolérance anti-rejeu Svix (secondes). */
    private const TOLERANCE_S = 300;

    /** Nombre max de pièces jointes enrichies (taille/URL) par e-mail. */
    private const MAX_ATTACHMENTS = 20;

    private function secret(): string
    {
        return (string) (config('services.resend.webhook_secret') ?: env('RESEND_WEBHOOK_SECRET', ''));
    }

    private function apiBase(): string
    {
        return rtrim((string) (config('services.resend.api_base') ?: 'https://api.resend.com'), '/');
    }

    private function apiKey(): string
    {
        return (string) (config('services.resend.key') ?: '');
    }

    /**
     * Vérifie la signature Svix d'un webhook Resend sur le corps brut.
     * signed_content = "{svix-id}.{svix-timestamp}.{rawBody}" ;
     * signature attendue = base64(HMAC-SHA256(signed_content, clé)),
     * clé = base64_decode(partie après "whsec_") ; comparaison en temps constant.
     * Le timestamp doit être à ±300 s (anti-rejeu).
     */
    public function verifySignature(string $rawBody, ?string $svixId, ?string $svixTimestamp, ?string $svixSignature): bool
    {
        $secret = $this->secret();
        if ($secret === '' || ! $svixId || ! $svixTimestamp || ! $svixSignature) {
            return false; // fail closed : pas de secret configuré = refus
        }
        if (! ctype_digit($svixTimestamp) || abs(time() - (int) $svixTimestamp) > self::TOLERANCE_S) {
            return false;
        }

        $key = str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret;
        $decoded = base64_decode($key, true);
        $keyBytes = $decoded !== false ? $decoded : $key;

        $signedContent = $svixId.'.'.$svixTimestamp.'.'.$rawBody;
        $expected = base64_encode(hash_hmac('sha256', $signedContent, $keyBytes, true));

        // svix-signature : liste d'entrées séparées par des espaces, "v1=<base64>"
        // (accepte aussi le délimiteur virgule historique "v1,<base64>").
        foreach (preg_split('/\s+/', trim($svixSignature)) as $part) {
            if (! str_starts_with($part, 'v1')) {
                continue;
            }
            $sig = substr($part, 2);
            $sig = preg_replace('/^[=,]/', '', $sig);
            if ($sig !== '' && hash_equals($expected, (string) $sig)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Corps complet d'un e-mail reçu : GET /emails/receiving/{id}
     * (repli sur /emails/received/{id}). Retourne null si l'appel échoue.
     */
    public function fetchReceived(string $emailId): ?array
    {
        $key = $this->apiKey();
        if ($key === '' || $emailId === '') {
            Log::warning('ResendInboundService: clé API absente ou email_id vide — récupération du corps impossible');

            return null;
        }
        $base = $this->apiBase();
        foreach (['/emails/receiving/', '/emails/received/'] as $path) {
            $response = Http::timeout(15)->withToken($key)->get($base.$path.$emailId);
            if ($response->successful()) {
                $data = $response->json();

                return is_array($data) ? $data : null;
            }
            if ($response->status() !== 404 && $response->status() !== 405) {
                Log::warning('ResendInboundService: échec récupération e-mail reçu, statut '.$response->status());

                return null;
            }
        }
        Log::warning('ResendInboundService: e-mail reçu introuvable côté Resend');

        return null;
    }

    /** Métadonnées enrichies d'une pièce jointe (taille + URL temporaire signée). */
    public function fetchAttachment(string $emailId, string $attachmentId): ?array
    {
        $key = $this->apiKey();
        if ($key === '' || $emailId === '' || $attachmentId === '') {
            return null;
        }
        $response = Http::timeout(15)->withToken($key)
            ->get($this->apiBase().'/emails/receiving/'.$emailId.'/attachments/'.$attachmentId);
        if (! $response->successful()) {
            return null;
        }
        $data = $response->json();

        return is_array($data) ? $data : null;
    }

    /**
     * Assainissement best-effort du HTML reçu (CONTRAT-MAIL.md §4) :
     * retire <script>, <iframe>, <object>, <embed>, <form>, les attributs on*=,
     * et les URL javascript:. Le front ré-assainit à l'affichage.
     */
    public function sanitizeHtml(string $html): string
    {
        $html = preg_replace('#<(script|iframe|object|embed|form|link|meta)\b[^>]*>.*?</\1\s*>#is', '', $html) ?? $html;
        $html = preg_replace('#<(script|iframe|object|embed|form|link|meta)\b[^>]*/?>#is', '', $html) ?? $html;
        $html = preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#is', '', $html) ?? $html;
        $html = preg_replace('#(href|src|action|formaction)\s*=\s*(["\']?)\s*javascript:[^"\'>\s]*#is', '$1=$2', $html) ?? $html;

        return $html;
    }

    /**
     * Enregistre un e-mail reçu (idempotent sur resend_email_id).
     * $webhookData = data du webhook (métadonnées) ; $full = corps complet API (peut être null).
     * Retourne [MailRecu|null, bool $dejaExistant].
     */
    public function storeReceived(array $webhookData, ?array $full): array
    {
        $emailId = (string) ($webhookData['email_id'] ?? ($full['id'] ?? ''));
        if ($emailId === '') {
            return [null, false];
        }

        $existing = MailRecu::where('resend_email_id', $emailId)->first();
        if ($existing) {
            return [$existing, true];
        }

        $full = $full ?? [];
        $from = (string) ($full['from'] ?? ($webhookData['from'] ?? ''));
        $to = $full['to'] ?? ($webhookData['to'] ?? []);
        $cc = $full['cc'] ?? ($webhookData['cc'] ?? []);
        $attachments = $full['attachments'] ?? ($webhookData['attachments'] ?? []);

        $pieces = [];
        foreach (array_slice(is_array($attachments) ? $attachments : [], 0, self::MAX_ATTACHMENTS) as $att) {
            if (! is_array($att) || empty($att['id'])) {
                continue;
            }
            $detail = $this->fetchAttachment($emailId, (string) $att['id']) ?? [];
            $pieces[] = [
                'id' => (string) $att['id'],
                'nom' => (string) ($att['filename'] ?? ($detail['filename'] ?? 'piece-jointe')),
                'type' => (string) ($att['content_type'] ?? ($detail['content_type'] ?? 'application/octet-stream')),
                'taille' => $detail['size'] ?? null,
                'url' => $detail['download_url'] ?? null, // URL temporaire signée
                'expire_le' => $detail['expires_at'] ?? null,
            ];
        }

        $html = $full['html'] ?? null;
        $mail = MailRecu::create([
            'resend_email_id' => $emailId,
            'expediteur' => $from,
            'destinataires' => is_array($to) ? array_values($to) : [$to],
            'copie' => is_array($cc) ? array_values($cc) : [$cc],
            'sujet' => $full['subject'] ?? ($webhookData['subject'] ?? null),
            'corps_texte' => $full['text'] ?? null,
            'corps_html' => is_string($html) && $html !== '' ? $this->sanitizeHtml($html) : null,
            'pieces_jointes' => $pieces,
            'message_id' => $full['message_id'] ?? ($webhookData['message_id'] ?? null),
            'recu_le' => \Illuminate\Support\Carbon::parse($full['created_at'] ?? ($webhookData['created_at'] ?? now())) ?? now(),
            'lu' => false,
        ]);

        return [$mail, false];
    }
}
