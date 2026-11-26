<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Run the migrations. */
    public function up(): void
    {
        Schema::table('mails_recus', function (Blueprint $table) {
            $table->foreignId('categorie_id')
                ->nullable()
                ->constrained('user_categories')
                ->nullOnDelete();
            $table->index('categorie_id');
        });
    }

    /** Reverse the migrations. */
    public function down(): void
    {
        Schema::table('mails_recus', function (Blueprint $table) {
            $table->dropConstrainedForeignId('categorie_id');
        });
    }
};
