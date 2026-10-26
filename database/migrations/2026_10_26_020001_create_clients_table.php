<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            // id : DDL identique à schema_sqlite.sql (INTEGER PRIMARY KEY AUTOINCREMENT, PRAGMA notnull=0)
            $table->integer('id')->autoIncrement()->nullable();
            $table->text('civilité')->nullable();
            $table->text('nom');
            $table->text('entreprise');
            $table->text('forme_juridique')->nullable();
            $table->text('rc')->nullable();
            $table->text('ifu')->nullable();
            $table->text('email');
            $table->text('telephone')->nullable();
            $table->text('adresse')->nullable();
            $table->text('pays')->nullable();
            $table->text('activite')->nullable();
            $table->text('mot_de_passe');
            $table->timestamp('date_inscription')->nullable()->useCurrent();
            $table->text('statut')->nullable()->default('actif');
            $table->text('role')->nullable()->default('client');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
