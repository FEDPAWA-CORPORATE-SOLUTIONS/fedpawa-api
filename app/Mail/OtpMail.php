<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Parité NotificationService::sendOtp (PHP) — même objet et même corps :
 * « Votre code de réinitialisation est : <strong>code</strong> — il expire
 * dans 15 minutes. »
 */
class OtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $otp,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Votre code de réinitialisation FEDPAWA',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: 'Votre code de réinitialisation est : <strong>'.e($this->otp)."</strong><br>Il expire dans 15 minutes. Si vous n'êtes pas à l'origine de cette demande, ignorez cet e-mail.",
        );
    }
}
