<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_messages', function (Blueprint $table) {
            $table->integer('id')->autoIncrement()->nullable();
            $table->integer('sender_id')->nullable();
            $table->text('sender_type');
            $table->integer('receiver_id')->nullable();
            $table->text('receiver_type');
            $table->text('content');
            $table->integer('is_read')->nullable()->default(0);
            $table->timestamp('created_at')->nullable()->useCurrent();
        });
        Schema::create('password_resets', function (Blueprint $table) {
            $table->integer('id')->autoIncrement()->nullable();
            $table->integer('client_id');
            $table->text('token_hash');
            $table->timestamp('expires_at');
            $table->integer('used')->nullable()->default(0);
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->foreign('client_id')->references('id')->on('clients');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_resets');
        Schema::dropIfExists('internal_messages');
    }
};
