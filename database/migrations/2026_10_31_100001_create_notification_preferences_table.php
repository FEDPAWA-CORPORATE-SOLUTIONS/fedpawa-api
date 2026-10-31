<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            // user_id = id du compte client (table clients) — l'« utilisateur »
            // de l'espace client ; cohérent avec push_subscriptions.client_id.
            $table->unsignedInteger('user_id');
            // 'global' pour le basculeur général, sinon un type du catalogue
            // (PushService::EVENT_TYPES).
            $table->string('type', 32);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'type']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
