<?php

namespace App\Services\Ai;

use App\Contracts\AiChatClientContract;
use App\Exceptions\AiClientException;
use Illuminate\Support\Str;

/**
 * Deterministic fake for the final AI contract (driver=fake / tests).
 * Returns the exact new-contract response shapes; never makes a network call.
 */
class FakeAiChatClient implements AiChatClientContract
{
    private string $mode = 'success';
    private ?string $message = null;
    private string $failCode = 'AI_ERROR';
    private string $failMessage = 'Fake AI error';
    private int $callCount = 0;

    public function shouldSucceed(?string $message = null): static
    {
        $this->mode = 'success';
        $this->message = $message;

        return $this;
    }

    public function shouldFail(string $code = 'AI_ERROR', string $message = 'Fake AI error'): static
    {
        $this->mode = 'failure';
        $this->failCode = $code;
        $this->failMessage = $message;

        return $this;
    }

    public function shouldTimeout(): static
    {
        $this->mode = 'timeout';

        return $this;
    }

    public function getCallCount(): int
    {
        return $this->callCount;
    }

    public function chat(int $userId, string $message, ?string $sessionId = null): array
    {
        $this->callCount++;
        $this->throwIfConfiguredToFail();

        return [
            'message'    => $this->message ?? 'Fake AI answer.',
            'user_id'    => $userId,
            'session_id' => ($sessionId !== null && $sessionId !== '')
                ? $sessionId
                : 'fake-session-' . Str::uuid()->toString(),
        ];
    }

    public function summarize(int $userId, string $sessionId): array
    {
        $this->callCount++;
        $this->throwIfConfiguredToFail();

        return [
            'status'          => 'created',
            'session_id'      => $sessionId,
            'user_id'         => $userId,
            'summary_preview' => 'Fake summary preview.',
            'reason'          => null,
        ];
    }

    private function throwIfConfiguredToFail(): void
    {
        if ($this->mode === 'failure') {
            throw new AiClientException($this->failCode, $this->failMessage);
        }

        if ($this->mode === 'timeout') {
            throw new AiClientException('TIMEOUT', 'The AI request timed out.');
        }
    }
}
