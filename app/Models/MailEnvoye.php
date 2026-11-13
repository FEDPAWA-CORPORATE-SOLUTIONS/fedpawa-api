<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * CONTRAT-MAIL.md §2 — table mails_envoyes (e-mails sortants admin).
 * Suit l'envoi d'e-mails depuis l'interface d'administration :
 * nouveau message ou réponse à un mail reçu.
 */
class MailEnvoye extends Model
{
    protected $table = 'mails_envoyes';

    protected $fillable = [
        'expediteur',
        'destinataire',
        'copie',
        'sujet',
        'corps_texte',
        'corps_html',
        'pieces_jointes',
        'message_id',
        'resend_email_id',
        'statut',
        'erreur',
        'envoye_le',
    ];

    protected $casts = [
        'copie' => 'array',
        'pieces_jointes' => 'array',
        'envoye_le' => 'datetime',
        'statut' => 'string',
    ];

    /**
     * L'e-mail a-t-il été envoyé avec succès ?
     */
    public function estEnvoye(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->statut === 'envoye',
        );
    }

    /**
     * L'envoi a-t-il échoué ?
     */
    public function estEchec(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->statut === 'echec',
        );
    }
}
