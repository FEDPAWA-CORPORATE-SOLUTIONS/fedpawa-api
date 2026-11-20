<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_signing_requests', function (Blueprint $table) {
            $table->string('source_contract_id')->nullable()->index()->after('id');
            $table->string('source_document_hash')->nullable()->after('title');
            $table->string('source_document_size')->nullable()->after('source_document_hash');
            $table->string('ip_address')->nullable()->after('sender_email');
            $table->string('user_agent')->nullable()->after('ip_address');
        });
    }

    public function down(): void
    {
        Schema::table('contract_signing_requests', function (Blueprint $table) {
            $table->dropColumn([
                'source_contract_id',
                'source_document_hash',
                'source_document_size',
                'ip_address',
                'user_agent',
            ]);
        });
    }
};
