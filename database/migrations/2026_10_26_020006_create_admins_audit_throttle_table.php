<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $table) {
            $table->integer('id')->autoIncrement()->nullable();
            $table->text('email');
            $table->text('mot_de_passe');
        });
        // Seed défini dans schema_sqlite.sql (INSERT OR IGNORE : idempotent)
        DB::insert('INSERT OR IGNORE INTO admins (email, mot_de_passe) VALUES (?, ?)', [
            'mme.law@fedpawa.com',
            '$argon2id$v=19$m=65536,t=4,p=1$N2xoNG9TdjkzUmFycjJtOQ$4C4pxqp+cIJHYvdwy0akG9JSPQ9X59Cft3sGVgVc1Z8',
        ]);
        Schema::create('audit_log', function (Blueprint $table) {
            $table->integer('id')->autoIncrement()->nullable();
            $table->text('action');
            $table->integer('user_id')->nullable();
            $table->text('user_type')->nullable();
            $table->timestamp('timestamp')->nullable()->useCurrent();
            $table->text('details')->nullable();
        });
        Schema::create('login_throttle', function (Blueprint $table) {
            $table->integer('id')->autoIncrement()->nullable();
            $table->text('identifier');
            $table->text('user_type');
            $table->text('ip')->nullable();
            $table->integer('attempts')->nullable()->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_attempt_at')->nullable()->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_throttle');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('admins');
    }
};
