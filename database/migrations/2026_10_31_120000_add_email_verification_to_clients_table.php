<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->timestamp('email_verified_at')->nullable();
            $table->string('email_verification_hash', 64)->nullable();
            $table->timestamp('email_verification_sent_at')->nullable();
        });

        // Backfill grand-fathering (CONTRAT-VERIF.md) : tous les clients
        // EXISTANTS sont considérés vérifiés (inscrits sous les anciennes
        // règles). Les NOUVEAUX inscrits partent non vérifiés (null).
        DB::table('clients')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()->format('Y-m-d H:i:s')]);
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['email_verified_at', 'email_verification_hash', 'email_verification_sent_at']);
        });
    }
};
