<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            // Tracks when the AI service last wrote a summary for this conversation.
            // Set by the backend in SummarizeChatConversation::handle() after a
            // successful summarize call; null until the first summary is created.
            $table->timestamp('summary_updated_at')->nullable()->after('deleted_by_student_at');
        });
    }

    public function down(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->dropColumn('summary_updated_at');
        });
    }
};
