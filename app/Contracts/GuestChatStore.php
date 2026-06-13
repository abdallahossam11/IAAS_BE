<?php

namespace App\Contracts;

interface GuestChatStore
{
    public function appendUserMessage(string $tokenHash, string $requestId, string $content): void;

    /**
     * @return array<int, array{request_id:string, role:string, content:string, created_at:string}>
     */
    public function getHistory(string $tokenHash): array;

    public function createRequest(string $requestId, string $tokenHash): void;

    /** @return array<string, string>|null */
    public function getRequest(string $requestId): ?array;

    public function acquirePending(string $tokenHash, string $requestId): bool;

    public function refreshPendingIfOwned(string $tokenHash, string $requestId): bool;

    public function clearPending(string $tokenHash, string $requestId): bool;

    public function markProcessing(string $requestId, string $tokenHash): bool;

    public function completeRequest(string $requestId, string $tokenHash, string $content): bool;

    public function failRequest(
        string $requestId,
        string $tokenHash,
        string $errorCode,
        string $errorMessage,
    ): bool;

    public function rollbackSubmission(string $tokenHash, string $requestId): void;

    public function refreshHistoryTtl(string $tokenHash): void;
}
