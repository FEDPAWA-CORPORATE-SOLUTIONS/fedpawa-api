<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mails_envoyes', function (Blueprint $table) {
            $table->id();
            $table->string('expediteur');              // adresse admin FEDPAWA
            $table->string('destinataire');            // adresse destinataire
            $table->json('copie')->nullable();         // adresses en copie
            $table->string('sujet');
            $table->longText('corps_texte')->nullable();
            $table->longText('corps_html')->nullable();
            $table->json('pieces_jointes')->nullable(); // [{nom, type, taille, url}]
            $table->string('message_id')->nullable();   // ID Resend de l'e-mail envoyé
            $table->string('resend_email_id')->nullable()->unique();
            $table->enum('statut', ['en_attente', 'envoye', 'echec'])->default('en_attente');
            $table->text('erreur')->nullable();         // message d'erreur si échec
            $table->timestamp('envoye_le')->nullable();
            $table->timestamps();
        });

        Schema::table('mails_envoyes', function (Blueprint $table) {
            $table->index(['statut', 'created_at']);
            $table->index('resend_email_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mails_envoyes');
    }
};
