<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * E-mail transactionnel du catalogue (BRIEF-B3 / CATALOGUE-EVENEMENTS.md) :
 * un gabarit Blade resources/views/emails/<NN>-<slug>.blade.php + un sujet
 * français par événement. Le rendu part via le mailer par défaut
 * (MAIL_MAILER=log en test → laravel.log ; resend le jour J).
 */
class EventMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $evenement,
        public string $sujet,
        public string $vue,
        public array $donnees = [],
    ) {}

    public function build(): static
    {
        return $this->subject($this->sujet)->view($this->vue, $this->donnees);
    }
}
