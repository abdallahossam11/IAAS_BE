<?php

namespace App\Services\Ai;

use App\Contracts\GuestAiChatClientContract;
use App\Exceptions\AiClientException;

class FakeGuestAiChatClient implements GuestAiChatClientContract
{
    private string $mode = 'success';
    private string $successContent = 'Fake guest AI response';
    private string $failCode = 'AI_ERROR';
    private string $failMessage = 'Fake AI error';
    private int $callCount = 0;
    private ?array $lastPayload = null;

    public function shouldSucceed(string $content = 'Fake guest AI response'): void
    {
        $this->mode           = 'success';
        $this->successContent = $content;
    }

    public function shouldFail(string $code = 'AI_ERROR', string $message = 'Fake AI error'): void
    {
        $this->mode        = 'fail';
        $this->failCode    = $code;
        $this->failMessage = $message;
    }

    public function shouldTimeout(): void
    {
        $this->mode = 'timeout';
    }

    public function getCallCount(): int
    {
        return $this->callCount;
    }

    public function getLastPayload(): ?array
    {
        return $this->lastPayload;
    }

    public function send(array $payload): array
    {
        $this->callCount++;
        $this->lastPayload = $payload;

        return match ($this->mode) {
            'fail'    => throw new AiClientException($this->failCode, $this->failMessage),
            'timeout' => throw new AiClientException('TIMEOUT', 'The AI request timed out.'),
            default   => ['content' => $this->successContent],
        };
    }
}
