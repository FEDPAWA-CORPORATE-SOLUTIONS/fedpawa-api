<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->index('transaction_id', 'idx_paiements_transaction');
        });
        Schema::table('internal_messages', function (Blueprint $table) {
            $table->index(['sender_id', 'receiver_id'], 'idx_messages_client');
        });
        Schema::table('login_throttle', function (Blueprint $table) {
            $table->unique(['identifier', 'user_type'], 'idx_throttle_ident');
        });
        Schema::table('clients', function (Blueprint $table) {
            $table->unique('email', 'clients_email_unique');
        });
        Schema::table('factures', function (Blueprint $table) {
            $table->unique('numero', 'factures_numero_unique');
        });
        Schema::table('contract_templates', function (Blueprint $table) {
            $table->unique('name', 'contract_templates_name_unique');
        });
        Schema::table('admins', function (Blueprint $table) {
            $table->unique('email', 'admins_email_unique');
        });
        Schema::table('password_resets', function (Blueprint $table) {
            $table->unique('token_hash', 'password_resets_token_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::table('password_resets', function (Blueprint $table) {
            $table->dropUnique('password_resets_token_hash_unique');
        });
        Schema::table('admins', function (Blueprint $table) {
            $table->dropUnique('admins_email_unique');
        });
        Schema::table('contract_templates', function (Blueprint $table) {
            $table->dropUnique('contract_templates_name_unique');
        });
        Schema::table('factures', function (Blueprint $table) {
            $table->dropUnique('factures_numero_unique');
        });
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique('clients_email_unique');
        });
        Schema::table('login_throttle', function (Blueprint $table) {
            $table->dropUnique('idx_throttle_ident');
        });
        Schema::table('internal_messages', function (Blueprint $table) {
            $table->dropIndex('idx_messages_client');
        });
        Schema::table('paiements', function (Blueprint $table) {
            $table->dropIndex('idx_paiements_transaction');
        });
    }
};
