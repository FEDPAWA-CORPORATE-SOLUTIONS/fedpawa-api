<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CONTRAT-MAIL.md §2 — réception des e-mails Resend (domaine racine fedpawa.com).
 * Table mails_recus : idempotente sur resend_email_id (unique).
 * Colonnes JSON : destinataires, copie, pieces_jointes (nom, type, taille, url temporaire).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mails_recus', function (Blueprint $table) {
            $table->id();
            $table->string('resend_email_id', 64)->unique(); // UUID Resend (data.email_id du webhook)
            $table->string('expediteur', 320);
            $table->json('destinataires')->nullable(); // to[] conservé tel quel (R2-B : toutes adresses acceptées)
            $table->json('copie')->nullable();         // cc[]
            $table->text('sujet')->nullable();
            $table->longText('corps_texte')->nullable();
            $table->longText('corps_html')->nullable(); // assaini côté serveur (scripts/iframes retirés)
            $table->json('pieces_jointes')->nullable(); // [{nom, type, taille, url, expire_le}]
            $table->string('message_id', 500)->nullable(); // Message-ID RFC
            $table->dateTime('recu_le');
            $table->boolean('lu')->default(false);
            $table->timestamps();

            $table->index('recu_le');
            $table->index('lu');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mails_recus');
    }
};
