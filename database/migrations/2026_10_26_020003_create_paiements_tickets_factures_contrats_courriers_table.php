<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paiements', function (Blueprint $table) {
            $table->integer('id')->autoIncrement()->nullable();
            $table->integer('client_id');
            $table->float('montant');
            $table->text('mode_paiement');
            $table->text('transaction_id')->nullable();
            $table->timestamp('date');
            $table->text('statut')->nullable()->default('en_attente');
            $table->foreign('client_id')->references('id')->on('clients');
        });
        Schema::create('tickets_support', function (Blueprint $table) {
            $table->integer('id')->autoIncrement()->nullable();
            $table->integer('client_id');
            $table->text('sujet');
            $table->text('message');
            $table->text('statut')->nullable()->default('open');
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->foreign('client_id')->references('id')->on('clients');
        });
        Schema::create('factures', function (Blueprint $table) {
            $table->integer('id')->autoIncrement()->nullable();
            $table->integer('client_id');
            $table->text('numero');
            $table->float('montant');
            $table->timestamp('date');
            $table->text('lien_pdf')->nullable();
            $table->foreign('client_id')->references('id')->on('clients');
        });
        Schema::create('contrats', function (Blueprint $table) {
            $table->integer('id')->autoIncrement()->nullable();
            $table->integer('client_id');
            $table->text('type');
            $table->text('fichier');
            $table->timestamp('date_signature');
            $table->foreign('client_id')->references('id')->on('clients');
        });
        Schema::create('courriers', function (Blueprint $table) {
            $table->integer('id')->autoIncrement()->nullable();
            $table->integer('client_id');
            $table->timestamp('date_reception');
            $table->text('scan')->nullable();
            $table->text('statut')->nullable()->default('non_lu');
            $table->foreign('client_id')->references('id')->on('clients');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courriers');
        Schema::dropIfExists('contrats');
        Schema::dropIfExists('factures');
        Schema::dropIfExists('tickets_support');
        Schema::dropIfExists('paiements');
    }
};
