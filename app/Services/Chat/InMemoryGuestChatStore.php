<?php

namespace App\Services\Chat;

use App\Contracts\GuestChatStore;

class InMemoryGuestChatStore implements GuestChatStore
{
    /** @var array<string, list<array{request_id:string,role:string,content:string,created_at:string}>> */
    private array $messages = [];

    /** @var array<string, int|null> */
    private array $messagesTtl = [];

    /** @var array<string, array<string,string>> */
    private array $requests = [];

    /** @var array<string, int|null> */
    private array $requestsTtl = [];

    /** @var array<string, string> */
    private array $pending = [];

    /** @var array<string, int|null> */
    private array $pendingTtl = [];

    private int $historyTtl;
    private int $pendingTtlS;

    public function __construct(?int $historyTtl = null, ?int $pendingTtl = null)
    {
        $this->historyTtl  = $historyTtl ?? (int) config('chat.guest_session_ttl', 86400);
        $this->pendingTtlS = $pendingTtl ?? (int) config('chat.guest_pending_ttl', 600);
    }

    private function now(): int
    {
        return time();
    }

    private function isExpired(int|null $expiresAt): bool
    {
        return $expiresAt !== null && $this->now() >= $expiresAt;
    }

    // ── simulated TTL helpers ────────────────────────────────────────────────

    public function simulateExpireHistory(string $tokenHash): void
    {
        $this->messagesTtl[$tokenHash] = $this->now() - 1;
    }

    public function simulateExpirePending(string $tokenHash): void
    {
        $this->pendingTtl[$tokenHash] = $this->now() - 1;
    }

    // ── appendUserMessage ────────────────────────────────────────────────────

    public function appendUserMessage(string $tokenHash, string $requestId, string $content): void
    {
        if (! isset($this->messages[$tokenHash]) || $this->isExpired($this->messagesTtl[$tokenHash] ?? null)) {
            $this->messages[$tokenHash] = [];
        }

        $this->messages[$tokenHash][] = [
            'request_id' => $requestId,
            'role'        => 'user',
            'content'     => $content,
            'created_at'  => now()->toIso8601String(),
        ];

        $this->messagesTtl[$tokenHash] = $this->now() + $this->historyTtl;
    }

    // ── getHistory ───────────────────────────────────────────────────────────

    public function getHistory(string $tokenHash): array
    {
        if ($this->isExpired($this->messagesTtl[$tokenHash] ?? null)) {
            return [];
        }

        return $this->messages[$tokenHash] ?? [];
    }

    // ── createRequest ────────────────────────────────────────────────────────

    public function createRequest(string $requestId, string $tokenHash): void
    {
        $this->requests[$requestId] = [
            'request_id'    => $requestId,
            'token_hash'    => $tokenHash,
            'status'        => 'queued',
            'content'       => '',
            'error_code'    => '',
            'error_message' => '',
            'created_at'    => now()->toIso8601String(),
            'updated_at'    => now()->toIso8601String(),
        ];

        $this->requestsTtl[$requestId] = $this->now() + $this->historyTtl;
    }

    // ── getRequest ───────────────────────────────────────────────────────────

    public function getRequest(string $requestId): ?array
    {
        if (! isset($this->requests[$requestId])) {
            return null;
        }

        if ($this->isExpired($this->requestsTtl[$requestId] ?? null)) {
            return null;
        }

        return $this->requests[$requestId];
    }

    // ── acquirePending ───────────────────────────────────────────────────────

    public function acquirePending(string $tokenHash, string $requestId): bool
    {
        if (isset($this->pending[$tokenHash]) && ! $this->isExpired($this->pendingTtl[$tokenHash] ?? null)) {
            return false;
        }

        $this->pending[$tokenHash]    = $requestId;
        $this->pendingTtl[$tokenHash] = $this->now() + $this->pendingTtlS;

        return true;
    }

    // ── refreshPendingIfOwned ────────────────────────────────────────────────

    public function refreshPendingIfOwned(string $tokenHash, string $requestId): bool
    {
        if ($this->isExpired($this->pendingTtl[$tokenHash] ?? null)) {
            return false;
        }

        if (! isset($this->pending[$tokenHash])) {
            return false;
        }

        if (! hash_equals($this->pending[$tokenHash], $requestId)) {
            return false;
        }

        $this->pendingTtl[$tokenHash] = $this->now() + $this->pendingTtlS;

        return true;
    }

    // ── clearPending ─────────────────────────────────────────────────────────

    public function clearPending(string $tokenHash, string $requestId): bool
    {
        if ($this->isExpired($this->pendingTtl[$tokenHash] ?? null)) {
            return false;
        }

        if (! isset($this->pending[$tokenHash])) {
            return false;
        }

        if (! hash_equals($this->pending[$tokenHash], $requestId)) {
            return false;
        }

        unset($this->pending[$tokenHash], $this->pendingTtl[$tokenHash]);

        return true;
    }

    // ── markProcessing ───────────────────────────────────────────────────────

    public function markProcessing(string $requestId, string $tokenHash): bool
    {
        $req = $this->getRequest($requestId);

        if ($req === null) {
            return false;
        }

        if (! hash_equals($req['token_hash'], $tokenHash)) {
            return false;
        }

        if ($req['status'] !== 'queued') {
            return false;
        }

        $pendingOwner = $this->pending[$tokenHash] ?? null;
        $expired      = $this->isExpired($this->pendingTtl[$tokenHash] ?? null);

        if ($expired || $pendingOwner === null || ! hash_equals($pendingOwner, $requestId)) {
            return false;
        }

        $this->requests[$requestId]['status']     = 'processing';
        $this->requests[$requestId]['updated_at'] = now()->toIso8601String();
        $this->requestsTtl[$requestId]            = $this->now() + $this->historyTtl;

        return true;
    }

    // ── completeRequest ──────────────────────────────────────────────────────

    public function completeRequest(string $requestId, string $tokenHash, string $content): bool
    {
        $req = $this->getRequest($requestId);

        if ($req === null) {
            return false;
        }

        if (! hash_equals($req['token_hash'], $tokenHash)) {
            return false;
        }

        if ($req['status'] !== 'processing') {
            return false;
        }

        $pendingOwner = $this->pending[$tokenHash] ?? null;
        $expired      = $this->isExpired($this->pendingTtl[$tokenHash] ?? null);

        if ($expired || $pendingOwner === null || ! hash_equals($pendingOwner, $requestId)) {
            return false;
        }

        // Append assistant message
        if (! isset($this->messages[$tokenHash]) || $this->isExpired($this->messagesTtl[$tokenHash] ?? null)) {
            $this->messages[$tokenHash] = [];
        }

        $this->messages[$tokenHash][] = [
            'request_id' => $requestId,
            'role'        => 'assistant',
            'content'     => $content,
            'created_at'  => now()->toIso8601String(),
        ];

        $this->messagesTtl[$tokenHash] = $this->now() + $this->historyTtl;

        $this->requests[$requestId]['status']     = 'completed';
        $this->requests[$requestId]['content']    = $content;
        $this->requests[$requestId]['error_code'] = '';
        $this->requests[$requestId]['error_message'] = '';
        $this->requests[$requestId]['updated_at'] = now()->toIso8601String();
        $this->requestsTtl[$requestId]            = $this->now() + $this->historyTtl;

        // Clear pending lock only if still owned
        if (isset($this->pending[$tokenHash]) && hash_equals($this->pending[$tokenHash], $requestId)) {
            unset($this->pending[$tokenHash], $this->pendingTtl[$tokenHash]);
        }

        return true;
    }

    // ── failRequest ──────────────────────────────────────────────────────────

    public function failRequest(
        string $requestId,
        string $tokenHash,
        string $errorCode,
        string $errorMessage,
    ): bool {
        $req = $this->getRequest($requestId);

        if ($req === null) {
            return false;
        }

        if (! hash_equals($req['token_hash'], $tokenHash)) {
            return false;
        }

        if (! in_array($req['status'], ['queued', 'processing'], true)) {
            return false;
        }

        $this->requests[$requestId]['status']        = 'failed';
        $this->requests[$requestId]['error_code']    = $errorCode;
        $this->requests[$requestId]['error_message'] = $errorMessage;
        $this->requests[$requestId]['updated_at']    = now()->toIso8601String();
        $this->requestsTtl[$requestId]               = $this->now() + $this->historyTtl;

        // Clear pending lock only if still owned
        if (isset($this->pending[$tokenHash]) && ! $this->isExpired($this->pendingTtl[$tokenHash] ?? null)
            && hash_equals($this->pending[$tokenHash], $requestId)) {
            unset($this->pending[$tokenHash], $this->pendingTtl[$tokenHash]);
        }

        return true;
    }

    // ── rollbackSubmission ───────────────────────────────────────────────────

    public function rollbackSubmission(string $tokenHash, string $requestId): void
    {
        // Remove request hash
        unset($this->requests[$requestId], $this->requestsTtl[$requestId]);

        // Remove matching user history entry
        if (isset($this->messages[$tokenHash]) && ! $this->isExpired($this->messagesTtl[$tokenHash] ?? null)) {
            $this->messages[$tokenHash] = array_values(
                array_filter(
                    $this->messages[$tokenHash],
                    fn (array $e) => ! ($e['role'] === 'user' && $e['request_id'] === $requestId),
                ),
            );

            // Refresh TTL only when history remains non-empty
            if (! empty($this->messages[$tokenHash])) {
                $this->messagesTtl[$tokenHash] = $this->now() + $this->historyTtl;
            } else {
                unset($this->messagesTtl[$tokenHash]);
            }
        }

        // Clear pending lock only if still owned
        if (isset($this->pending[$tokenHash]) && ! $this->isExpired($this->pendingTtl[$tokenHash] ?? null)
            && hash_equals($this->pending[$tokenHash], $requestId)) {
            unset($this->pending[$tokenHash], $this->pendingTtl[$tokenHash]);
        }
    }

    // ── refreshHistoryTtl ────────────────────────────────────────────────────

    public function refreshHistoryTtl(string $tokenHash): void
    {
        if (isset($this->messages[$tokenHash]) && ! $this->isExpired($this->messagesTtl[$tokenHash] ?? null)) {
            $this->messagesTtl[$tokenHash] = $this->now() + $this->historyTtl;
        }
    }
}
