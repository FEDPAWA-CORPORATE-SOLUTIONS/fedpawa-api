<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');
            $table->string('signer_email');
            $table->string('payer_email');
            $table->string('flow_token')->unique();
            $table->string('statut'); // draft, awaiting_signature, signed_awaiting_payment, completed
            $table->string('pack');
            $table->float('prix');
            $table->json('contrat_data'); // snapshot des données du formulaire (forfait, tarif, client, etc.)
            $table->string('hash_brouillon')->nullable(); // hash du brouillon approuvé (étape 2)
            $table->string('hash_sha256')->nullable(); // hash du PDF final signé (étape 3)
            $table->string('signed_pdf_path')->nullable(); // chemin du PDF signé stocké
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('session_id')->nullable();
            $table->string('paiement_transaction_id')->nullable();
            $table->timestamp('otp_verified_at')->nullable();
            $table->timestamps();

            $table->index('flow_token');
            $table->index('statut');
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_drafts');
    }
};
