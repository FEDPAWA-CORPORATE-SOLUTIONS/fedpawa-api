<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * CONTRAT-MAIL.md §2 — table mails_recus (réception Resend, webhook email.received).
 * Idempotence : resend_email_id unique. lu = état lu/non lu (API admin).
 */
class MailRecu extends Model
{
    protected $table = 'mails_recus';

    protected $fillable = [
        'resend_email_id', 'expediteur', 'destinataires', 'copie', 'sujet',
        'corps_texte', 'corps_html', 'pieces_jointes', 'message_id', 'recu_le', 'lu', 'categorie_id', 'imap_account', 'imap_uid',
    ];

    protected $casts = [
        'destinataires' => 'array',
        'copie' => 'array',
        'pieces_jointes' => 'array',
        'recu_le' => 'datetime',
        'lu' => 'boolean',
        'categorie_id' => 'integer',
        'imap_account' => 'integer',
        'imap_uid' => 'integer',
    ];

    public function categorie()
    {
        return $this->belongsTo(\App\Models\UserCategory::class, 'categorie_id');
    }
}
