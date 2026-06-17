<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Read-only view the AI service uses to read signed-in chat memory.
     *
     * Exposes exactly: session_id, user_id, role, content, created_at.
     *
     * Rules:
     *  - Only signed-in student conversations (guests have no DB rows).
     *  - Only conversations whose session_id is set (NULL = not yet answered).
     *  - Only completed user/assistant messages.
     *  - Excludes the in-flight, unanswered user turn: a user message appears
     *    only once a later completed assistant message exists in the same
     *    conversation. The AI receives the current message in the /api/chat
     *    request body, so the view must expose previous history only.
     *
     * Plain SELECT/JOIN/EXISTS — portable across MySQL and SQLite (tests).
     */
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS ai_chat_messages');

        DB::statement(<<<'SQL'
            CREATE VIEW ai_chat_messages AS
            SELECT
                cc.session_id AS session_id,
                cc.student_id AS user_id,
                cm.role       AS role,
                cm.content    AS content,
                cm.created_at AS created_at
            FROM chat_messages cm
            INNER JOIN chat_conversations cc
                ON cc.id = cm.chat_conversation_id
            WHERE cc.session_id IS NOT NULL
              AND cm.role IN ('user', 'assistant')
              AND cm.status = 'completed'
              AND (
                    cm.role = 'assistant'
                    OR EXISTS (
                        SELECT 1
                        FROM chat_messages a
                        WHERE a.chat_conversation_id = cm.chat_conversation_id
                          AND a.role = 'assistant'
                          AND a.status = 'completed'
                          AND a.sequence_number > cm.sequence_number
                    )
              )
            SQL
        );
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ai_chat_messages');
    }
};
