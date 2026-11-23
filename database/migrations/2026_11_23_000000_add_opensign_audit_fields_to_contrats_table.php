<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contrats', function (Blueprint $table) {
            // Preuve d'audit OpenSign : empreinte SHA-256 du document signé
            $table->text('empreinte_sha256')->nullable()->after('date_scelle');
            // Certificat de completion généré par OpenSign (piste d'audit)
            $table->longText('certificat_completion')->nullable()->after('empreinte_sha256');
        });
    }

    public function down(): void
    {
        Schema::table('contrats', function (Blueprint $table) {
            $table->dropColumn(['empreinte_sha256', 'certificat_completion']);
        });
    }
};
