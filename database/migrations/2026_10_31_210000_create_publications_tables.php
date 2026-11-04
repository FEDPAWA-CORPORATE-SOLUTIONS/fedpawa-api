<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CONTRAT-PUBLICATIONS.md §1 :
 * - publications          : titre/chapeau/contenu, type (4), statut (4),
 *                           publie_le (programmation), auteur_id (fk clients.id),
 *                           vues ;
 * - publication_medias    : médias uploadés (disque public), ordre (galerie) ;
 * - publication_lectures  : lu par client (unicité publication_id+client_id),
 *                           sert le compteur de non-lues et le marquage auto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publications', function (Blueprint $table) {
            $table->id();
            $table->string('titre', 200);
            $table->string('chapeau', 300)->nullable();
            $table->text('contenu'); // HTML simple
            $table->string('type', 20)->default('texte'); // texte|texte_image|texte_video|galerie
            $table->string('statut', 20)->default('brouillon'); // brouillon|programme|publie|depublie
            $table->dateTime('publie_le')->nullable();
            $table->unsignedInteger('auteur_id'); // fk clients.id (compte admin)
            $table->unsignedInteger('vues')->default(0);
            $table->timestamps();

            $table->foreign('auteur_id')->references('id')->on('clients');
            $table->index('statut');
            $table->index('publie_le');
        });

        Schema::create('publication_medias', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('publication_id');
            $table->string('type', 10); // image|video
            $table->string('chemin'); // chemin relatif sur le disque « public »
            $table->string('nom_origine');
            $table->string('mime', 100);
            $table->unsignedBigInteger('taille_octets');
            $table->unsignedInteger('ordre')->default(0);
            $table->timestamps();

            $table->foreign('publication_id')->references('id')->on('publications')->cascadeOnDelete();
            $table->index('publication_id');
        });

        Schema::create('publication_lectures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('publication_id');
            $table->unsignedInteger('client_id'); // fk clients.id
            $table->dateTime('lu_le');
            $table->timestamps();

            $table->unique(['publication_id', 'client_id']);
            $table->foreign('publication_id')->references('id')->on('publications')->cascadeOnDelete();
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publication_lectures');
        Schema::dropIfExists('publication_medias');
        Schema::dropIfExists('publications');
    }
};
