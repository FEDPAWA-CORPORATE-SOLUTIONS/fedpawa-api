<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc', function (Blueprint $table) {
            $table->integer('id')->autoIncrement()->nullable();
            $table->integer('client_id');
            $table->text('piece_identite')->nullable();
            $table->text('statuts')->nullable()->default('en_cours');
            $table->text('justificatif_domicile')->nullable();
            $table->text('description_activite')->nullable();
            $table->timestamp('date_soumission')->nullable()->useCurrent();
            $table->foreign('client_id')->references('id')->on('clients');
        });
        Schema::create('contract_templates', function (Blueprint $table) {
            $table->integer('id')->autoIncrement()->nullable();
            $table->text('name');
            $table->text('content');
            $table->timestamp('updated_at')->nullable()->useCurrent();
        });
        Schema::create('contract_history', function (Blueprint $table) {
            $table->integer('id')->autoIncrement()->nullable();
            $table->integer('template_id');
            $table->text('old_content')->nullable();
            $table->timestamp('changed_at')->nullable()->useCurrent();
            $table->foreign('template_id')->references('id')->on('contract_templates');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_history');
        Schema::dropIfExists('contract_templates');
        Schema::dropIfExists('kyc');
    }
};
