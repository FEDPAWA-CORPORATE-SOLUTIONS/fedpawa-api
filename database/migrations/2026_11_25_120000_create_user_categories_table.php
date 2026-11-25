<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_categories', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 100); // nom de la catégorie (ex: "Utilisateurs inscrits")
            $table->string('couleur', 20)->default('#1a73e8'); // couleur d'affichage dans l'onglet
            $table->boolean('est_systeme')->default(false); // true pour les catégories par défaut (inscrits, actifs, favoris)
            $table->timestamps();

            $table->index('est_systeme');
            $table->index('nom');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_categories');
    }
};
