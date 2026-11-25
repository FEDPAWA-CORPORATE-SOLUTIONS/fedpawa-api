<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Colonnes manquantes sur abonnements
        Schema::table('abonnements', function (Blueprint $table) {
            if (! Schema::hasColumn('abonnements', 'periode')) {
                $table->text('periode')->nullable()->after('prix');
            }
            if (! Schema::hasColumn('abonnements', 'numero_dossier')) {
                $table->text('numero_dossier')->nullable()->after('periode');
            }
            if (! Schema::hasColumn('abonnements', 'date_validation')) {
                $table->timestamp('date_validation')->nullable()->after('date_fin');
            }
        });

        // Colonnes manquantes sur paiements
        Schema::table('paiements', function (Blueprint $table) {
            if (! Schema::hasColumn('paiements', 'numero_dossier')) {
                $table->text('numero_dossier')->nullable()->after('transaction_id');
            }
            if (! Schema::hasColumn('paiements', 'date_validation')) {
                $table->timestamp('date_validation')->nullable()->after('date');
            }
            if (! Schema::hasColumn('paiements', 'simule')) {
                $table->boolean('simule')->default(0)->after('statut');
            }
        });
    }

    public function down(): void
    {
        Schema::table('abonnements', function (Blueprint $table) {
            $table->dropColumn(['periode', 'numero_dossier', 'date_validation']);
        });
        Schema::table('paiements', function (Blueprint $table) {
            $table->dropColumn(['numero_dossier', 'date_validation', 'simule']);
        });
    }
};
