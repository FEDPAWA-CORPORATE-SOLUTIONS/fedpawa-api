<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Empreinte d'appareil pour la détection « connexion depuis un nouvel
        // appareil » (notification compte_securite). Hash du User-Agent, jamais
        // le UA en clair.
        Schema::create('login_devices', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('client_id');
            $table->string('ua_hash', 64);
            $table->string('ip', 45)->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->unique(['client_id', 'ua_hash']);
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_devices');
    }
};
