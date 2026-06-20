<?php

namespace App\Jobs;

use App\Contracts\AiChatClientContract;
use App\Contracts\GuestChatStore;
use App\Exceptions\AiClientException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessGuestAiChat implements ShouldQueue
{
    use Queueable;

    public int $timeout = 450;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly string $requestId,
        public readonly string $tokenHash,
    ) {}

    /**
     * Final AI contract for guests: user_id = 0, send only the current message
     * (+ the AI session_id when continuing). The opaque backend guest token /
     * token hash is NEVER sent to the AI; guest memory lives in the AI's Redis
     * keyed by the AI session_id.
     */
    public function handle(AiChatClientContract $client, GuestChatStore $store): void
    {
        // 1. Load request state; exit if missing/expired.
        $req = $store->getRequest($this->requestId);
        if ($req === null) {
            return;
        }

        // 2. Verify ownership.
        if (! hash_equals($req['token_hash'], $this->tokenHash)) {
            return;
        }

        // 3. Refresh pending lock; fail if no longer owned.
        if (! $store->refreshPendingIfOwned($this->tokenHash, $this->requestId)) {
            $store->failRequest($this->requestId, $this->tokenHash, 'PENDING_LOCK_LOST', 'The pending lock is no longer owned by this request.');

            return;
        }

        // 4. Transition to processing.
        if (! $store->markProcessing($this->requestId, $this->tokenHash)) {
            return;
        }

        // 5. Resolve the current guest message (the user history entry for this request).
        $currentMessage = null;
        foreach ($store->getHistory($this->tokenHash) as $entry) {
            if (($entry['role'] ?? '') === 'user' && ($entry['request_id'] ?? '') === $this->requestId) {
                $currentMessage = $entry['content'] ?? null;
                break;
            }
        }

        if (! is_string($currentMessage) || trim($currentMessage) === '') {
            throw new AiClientException('INVALID_AI_RESPONSE', 'The guest request is missing its user message.');
        }

        // 6. The stored AI session id (null for a new guest chat).
        $sessionId = $store->getAiSessionId($this->tokenHash);

        // 7. Call the AI with user_id = 0. The client validates the response shape
        //    and that the echoed user_id matches.
        $result = $client->chat(0, $currentMessage, $sessionId);
        $returnedSessionId = $result['session_id'];

        // 8. Session-id integrity: continuing chats must echo the same session id.
        if ($sessionId !== null && $returnedSessionId !== $sessionId) {
            throw new AiClientException('INVALID_AI_RESPONSE', 'The AI service returned a mismatched session id.');
        }

        // 9. Complete atomically (also refreshes TTL). If the lock was replaced
        //    since we started, fail with PENDING_LOCK_LOST.
        $completed = $store->completeRequest($this->requestId, $this->tokenHash, $result['message']);

        if (! $completed) {
            $store->failRequest($this->requestId, $this->tokenHash, 'PENDING_LOCK_LOST', 'The pending lock is no longer owned by this request.');

            return;
        }

        // 10. New guest chat: persist the AI-generated session id (TTL refreshed).
        if ($sessionId === null) {
            $store->setAiSessionId($this->tokenHash, $returnedSessionId);
        }
    }

    public function failed(\Throwable $e): void
    {
        $errorCode = $e instanceof AiClientException ? $e->errorCode : 'UNEXPECTED_ERROR';
        $errorMessage = $e instanceof AiClientException ? $e->getMessage() : 'An unexpected error occurred.';

        app(GuestChatStore::class)->failRequest(
            $this->requestId,
            $this->tokenHash,
            $errorCode,
            $errorMessage,
        );

        // Failure-only, secret-safe structured log. The request id is the AI
        // request UUID; the raw guest token, token hash, AI session id, message
        // content, and payload are never logged.
        Log::warning('AI chat request failed', [
            'chat_type' => 'guest',
            'request_id' => $this->requestId,
            'status' => 'failed',
            'error_code' => $errorCode,
        ]);
    }
}
