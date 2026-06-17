<?php

namespace App\Jobs;

use App\Contracts\AiChatClientContract;
use App\Exceptions\AiClientException;
use App\Models\ChatConversation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Calls the AI summarize endpoint for one signed-in conversation. The AI service
 * owns chat_summaries (create/update) — the backend never writes the summary
 * text itself. Unique per conversation to avoid duplicate concurrent summaries.
 */
class SummarizeChatConversation implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 450;
    public int $tries = 1;
    public bool $failOnTimeout = true;

    /** Release the uniqueness lock after this many seconds as a safety net. */
    public int $uniqueFor = 3600;

    public function __construct(public readonly int $conversationId) {}

    public function uniqueId(): string
    {
        return 'summarize-chat-' . $this->conversationId;
    }

    public function handle(AiChatClientContract $client): void
    {
        $conversation = ChatConversation::find($this->conversationId);

        // Guests are never summarized (no DB rows); a null session_id means the
        // AI has not yet established a session for this conversation.
        if ($conversation === null || $conversation->session_id === null) {
            return;
        }

        $userId    = (int) $conversation->student_id;
        $sessionId = $conversation->session_id;

        // The client validates the response shape + that user_id/session_id match.
        $result = $client->summarize($userId, $sessionId);

        // created / updated → success (AI wrote chat_summaries). skipped is
        // unexpected for a signed-in chat but safe — warn and move on.
        if ($result['status'] === 'skipped') {
            Log::warning('Chat summary skipped', [
                'chat_type'         => 'student',
                'conversation_uuid' => $conversation->uuid,
                'session_id'        => $sessionId,
                'user_id'           => $userId,
                'status'            => 'skipped',
                'reason'            => $result['reason'],
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        $errorCode = $e instanceof AiClientException ? $e->errorCode : 'UNEXPECTED_ERROR';

        $conversation = ChatConversation::find($this->conversationId);

        // Failure-only, secret-safe log: identifiers + code only. Never logs
        // tokens, Authorization, payload, message content, or student PII.
        Log::warning('Chat summary failed', [
            'chat_type'         => 'student',
            'conversation_uuid' => optional($conversation)->uuid,
            'status'            => 'failed',
            'error_code'        => $errorCode,
        ]);
    }
}
