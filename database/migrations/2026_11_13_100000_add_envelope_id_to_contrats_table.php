<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contrats', function (Blueprint $table) {
            // Lien vers l'enveloppe OpenSign (si signature électronique demandée)
            $table->text('envelope_id')->nullable()->after('fichier');
            // Date à laquelle le contrat a été scellé (PAdES + horodatage)
            $table->timestamp('date_scelle')->nullable()->after('date_signature');
        });
    }

    public function down(): void
    {
        Schema::table('contrats', function (Blueprint $table) {
            $table->dropColumn(['envelope_id', 'date_scelle']);
        });
    }
};
