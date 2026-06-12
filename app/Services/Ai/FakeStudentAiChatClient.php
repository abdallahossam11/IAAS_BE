<?php

namespace App\Services\Ai;

use App\Contracts\StudentAiChatClientContract;
use App\Exceptions\AiClientException;

class FakeStudentAiChatClient implements StudentAiChatClientContract
{
    private string $mode = 'success';
    private string $successContent = 'Fake student AI response';
    private string $failCode = 'AI_ERROR';
    private string $failMessage = 'Fake AI error';
    private int $callCount = 0;
    private ?array $lastPayload = null;

    public function shouldSucceed(string $content = 'Fake student AI response'): static
    {
        $this->mode = 'success';
        $this->successContent = $content;

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

    public function getLastPayload(): ?array
    {
        return $this->lastPayload;
    }

    public function wasCalledWith(array $expected): bool
    {
        return $this->lastPayload === $expected;
    }

    public function send(array $payload): array
    {
        $this->callCount++;
        $this->lastPayload = $payload;

        return match ($this->mode) {
            'success' => ['content' => $this->successContent],
            'failure' => throw new AiClientException($this->failCode, $this->failMessage),
            'timeout' => throw new AiClientException('TIMEOUT', 'The AI request timed out.'),
            default   => throw new AiClientException('AI_ERROR', 'Unknown fake mode.'),
        };
    }
}
