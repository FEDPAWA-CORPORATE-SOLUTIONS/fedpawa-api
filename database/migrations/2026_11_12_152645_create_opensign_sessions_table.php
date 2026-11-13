<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('opensign_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('renewed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('opensign_sessions');
    }
};
