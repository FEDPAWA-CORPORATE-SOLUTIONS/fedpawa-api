<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_signing_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_id')->unique()->index(); // identifiant Portant (32 hex)
            $table->string('request_token')->nullable(); // token pour téléchargement /signed-pdf
            $table->string('title');
            $table->string('signer_email');
            $table->string('signer_name')->nullable();
            $table->string('sender_email')->nullable();
            $table->string('sender_name')->nullable();
            $table->string('status')->default('pending'); // pending, signed, cancelled, expired, error
            $table->string('document_url')->nullable(); // URL du PDF source envoyé
            $table->string('signed_pdf_url')->nullable(); // URL du PDF signé retourné par Portant
            $table->text('api_response')->nullable(); // réponse brute JSON de Portant (débug)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_signing_requests');
    }
};
