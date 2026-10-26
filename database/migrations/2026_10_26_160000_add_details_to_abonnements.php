<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colonne abonnements.details (JSON TEXT) — migration incrémentale
 * du schéma source Fedpawa (MigrationRunner PHP) : les détails du
 * formulaire de souscription (rccm, niu, siege, representant,
 * qualite, capital, entreprise, contact) sont persistés en JSON ici.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('abonnements')) {
            return;
        }
        if (! Schema::hasColumn('abonnements', 'details')) {
            Schema::table('abonnements', function (Blueprint $table) {
                $table->text('details')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('abonnements', 'details')) {
            Schema::table('abonnements', function (Blueprint $table) {
                $table->dropColumn('details');
            });
        }
    }
};
