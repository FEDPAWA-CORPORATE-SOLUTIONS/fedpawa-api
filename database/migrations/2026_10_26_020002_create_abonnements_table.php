<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abonnements', function (Blueprint $table) {
            $table->integer('id')->autoIncrement()->nullable();
            $table->integer('client_id');
            $table->text('pack');
            $table->float('prix');
            $table->integer('engagement')->nullable()->default(0);
            $table->timestamp('date_debut');
            $table->timestamp('date_fin')->nullable();
            $table->text('statut')->nullable()->default('en_attente');
            $table->foreign('client_id')->references('id')->on('clients');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abonnements');
    }
};
