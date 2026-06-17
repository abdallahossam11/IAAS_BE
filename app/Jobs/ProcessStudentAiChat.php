<?php

namespace App\Jobs;

use App\Contracts\AiChatClientContract;
use App\Exceptions\AiClientException;
use App\Models\ChatAiRequest;
use App\Models\ChatMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessStudentAiChat implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 450;
    public int $tries = 1;
    public bool $failOnTimeout = true;

    public function __construct(public readonly int $aiRequestId) {}

    /**
     * Final AI contract: send only the current user message + user_id (and the
     * session_id when continuing). Prior history is read by the AI from the
     * ai_chat_messages DB view, so no history is sent from the backend.
     */
    public function handle(AiChatClientContract $client): void
    {
        // Atomic claim — prevents double-processing under concurrent retries.
        $claimed = ChatAiRequest::query()
            ->whereKey($this->aiRequestId)
            ->where('status', ChatAiRequest::STATUS_QUEUED)
            ->update([
                'status'       => ChatAiRequest::STATUS_PROCESSING,
                'submitted_at' => now(),
            ]);

        if ($claimed !== 1) {
            return;
        }

        $aiRequest = ChatAiRequest::find($this->aiRequestId);
        if ($aiRequest === null) {
            return;
        }

        $conversation = $aiRequest->conversation;
        $userMessage  = ChatMessage::find($aiRequest->user_message_id);

        if ($conversation === null || $userMessage === null || ! is_string($userMessage->content)) {
            throw new AiClientException('INVALID_AI_RESPONSE', 'The chat request is missing its conversation or user message.');
        }

        $userId            = (int) $conversation->student_id;
        $existingSessionId = $conversation->session_id; // null until the AI first responds

        // The client validates the response shape and that user_id matches.
        $result = $client->chat($userId, $userMessage->content, $existingSessionId);

        $returnedSessionId = $result['session_id'];

        // Session-id integrity: for an existing chat the AI must echo the same
        // session_id. A mismatch means the response is not trustworthy.
        if ($existingSessionId !== null && $returnedSessionId !== $existingSessionId) {
            throw new AiClientException('INVALID_AI_RESPONSE', 'The AI service returned a mismatched session id.');
        }

        DB::transaction(function () use ($aiRequest, $conversation, $result, $existingSessionId, $returnedSessionId) {
            // New chat: persist the AI-generated session id. Never overwrite an
            // existing one (the mismatch guard above already rejected those).
            if ($existingSessionId === null) {
                $conversation->update(['session_id' => $returnedSessionId]);
            }

            ChatMessage::where('id', $aiRequest->assistant_message_id)->update([
                'content' => $result['message'],
                'status'  => ChatMessage::STATUS_COMPLETED,
            ]);

            $aiRequest->update([
                'status'       => ChatAiRequest::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);
        });
    }

    public function failed(\Throwable $exception): void
    {
        $errorCode    = $exception instanceof AiClientException ? $exception->errorCode    : 'UNEXPECTED_ERROR';
        $errorMessage = $exception instanceof AiClientException ? $exception->getMessage() : 'An unexpected error occurred.';

        $aiRequest = ChatAiRequest::find($this->aiRequestId);
        if ($aiRequest === null) {
            return;
        }

        DB::transaction(function () use ($aiRequest, $errorCode, $errorMessage) {
            $aiRequest->update([
                'status'        => ChatAiRequest::STATUS_FAILED,
                'failed_at'     => now(),
                'error_code'    => $errorCode,
                'error_message' => $errorMessage,
            ]);

            ChatMessage::where('id', $aiRequest->assistant_message_id)->update([
                'status' => ChatMessage::STATUS_FAILED,
            ]);
        });

        // Failure-only, secret-safe structured log. Never logs tokens, PII,
        // message content, or the payload — only stable identifiers and codes.
        Log::warning('AI chat request failed', [
            'chat_type'         => 'student',
            'ai_request_uuid'   => $aiRequest->uuid,
            'conversation_uuid' => optional($aiRequest->conversation)->uuid,
            'status'            => ChatAiRequest::STATUS_FAILED,
            'error_code'        => $errorCode,
            'attempt_number'    => $aiRequest->attempt_number,
        ]);
    }
}
