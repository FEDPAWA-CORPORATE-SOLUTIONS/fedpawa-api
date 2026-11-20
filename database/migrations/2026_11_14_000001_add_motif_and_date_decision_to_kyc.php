<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc', function (Blueprint $table) {
            if (! Schema::hasColumn('kyc', 'motif_rejet')) {
                $table->text('motif_rejet')->nullable()->after('statuts');
            }
            if (! Schema::hasColumn('kyc', 'date_decision')) {
                $table->timestamp('date_decision')->nullable()->after('motif_rejet');
            }
        });
    }

    public function down(): void
    {
        Schema::table('kyc', function (Blueprint $table) {
            $table->dropColumn(['motif_rejet', 'date_decision']);
        });
    }
};
