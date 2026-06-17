<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            // AI-generated session id. Stays NULL until the AI service responds
            // for the first time. The backend's stable route/admin/polling id
            // remains `uuid`; this column is the AI memory key.
            $table->string('session_id')->nullable()->after('uuid');

            // Unique when present (MySQL and SQLite both allow multiple NULLs in
            // a unique index, so existing/new unanswered rows are unaffected).
            $table->unique('session_id');
        });
    }

    public function down(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->dropUnique(['session_id']);
            $table->dropColumn('session_id');
        });
    }
};
