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
            $table->unsignedInteger('imap_uid')->nullable()->after('imap_account');
            $table->index('imap_uid');
        });
    }

    /** Reverse the migrations. */
    public function down(): void
    {
        Schema::table('mails_recus', function (Blueprint $table) {
            $table->dropIndex(['imap_uid']);
            $table->dropColumn('imap_uid');
        });
    }
};
