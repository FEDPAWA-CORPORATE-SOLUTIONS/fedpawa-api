<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_devices', function (Blueprint $table) {
            $table->unique(['client_id', 'ua_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('login_devices', function (Blueprint $table) {
            $table->dropUnique(['client_id', 'ua_hash']);
        });
    }
};
