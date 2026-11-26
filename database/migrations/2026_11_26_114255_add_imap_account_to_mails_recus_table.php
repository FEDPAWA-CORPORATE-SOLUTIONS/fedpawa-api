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
            $table->unsignedTinyInteger('imap_account')->nullable()->after('categorie_id');
            $table->index('imap_account');
        });
    }

    /** Reverse the migrations. */
    public function down(): void
    {
        Schema::table('mails_recus', function (Blueprint $table) {
            $table->dropIndex(['imap_account']);
            $table->dropColumn('imap_account');
        });
    }
};
