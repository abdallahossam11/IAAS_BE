<?php

namespace App\Jobs;

use App\Contracts\GuestAiChatClientContract;
use App\Contracts\GuestChatStore;
use App\Exceptions\AiClientException;
use App\Services\Ai\GuestPayloadBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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

    public function handle(GuestAiChatClientContract $client, GuestChatStore $store): void
    {
        // 1. Load request state; exit if missing/expired
        $req = $store->getRequest($this->requestId);

        if ($req === null) {
            return;
        }

        // 2. Verify ownership
        if (! hash_equals($req['token_hash'], $this->tokenHash)) {
            return;
        }

        // 3. Refresh pending lock; fail if no longer owned
        if (! $store->refreshPendingIfOwned($this->tokenHash, $this->requestId)) {
            $store->failRequest(
                $this->requestId,
                $this->tokenHash,
                'PENDING_LOCK_LOST',
                'The pending lock is no longer owned by this request.',
            );

            return;
        }

        // 4. Transition to processing
        if (! $store->markProcessing($this->requestId, $this->tokenHash)) {
            return;
        }

        // 5. Load history
        $history = $store->getHistory($this->tokenHash);

        // 6. Build payload
        $payload = (new GuestPayloadBuilder())->build($this->requestId, $this->tokenHash, $history);

        // 7. Call AI client
        $response = $client->send($payload);

        // 8. Validate response shape
        if (! isset($response['content']) || ! is_string($response['content'])) {
            throw new AiClientException('INVALID_AI_RESPONSE', 'The AI service returned an invalid response.');
        }

        // 9. Complete request atomically
        $completed = $store->completeRequest($this->requestId, $this->tokenHash, $response['content']);

        // 10. If lock was replaced since we started, fail with PENDING_LOCK_LOST
        if (! $completed) {
            $store->failRequest(
                $this->requestId,
                $this->tokenHash,
                'PENDING_LOCK_LOST',
                'The pending lock is no longer owned by this request.',
            );
        }
    }

    public function failed(\Throwable $e): void
    {
        $errorCode    = $e instanceof AiClientException ? $e->errorCode    : 'UNEXPECTED_ERROR';
        $errorMessage = $e instanceof AiClientException ? $e->getMessage() : 'An unexpected error occurred.';

        app(GuestChatStore::class)->failRequest(
            $this->requestId,
            $this->tokenHash,
            $errorCode,
            $errorMessage,
        );
    }
}
