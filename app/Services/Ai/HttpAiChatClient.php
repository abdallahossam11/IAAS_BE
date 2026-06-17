<?php

namespace App\Services\Ai;

use App\Contracts\AiChatClientContract;
use App\Exceptions\AiClientException;
use Illuminate\Http\Client\Response;

/**
 * HTTP client for the final AI contract.
 *
 * Reuses {@see AiHttpTransport} (URL/token guard, single bearer token,
 * ConnectionException → TIMEOUT/AI_CONNECTION_ERROR mapping). Never logs or
 * surfaces tokens, headers, request bodies, or raw responses.
 */
class HttpAiChatClient implements AiChatClientContract
{
    /** HTTP status → safe internal error code (non-200 responses). */
    private const STATUS_MAP = [
        400 => 'AI_VALIDATION_ERROR',
        401 => 'AI_UNAUTHORIZED',
        422 => 'AI_VALIDATION_ERROR',
        429 => 'AI_RATE_LIMITED',
        500 => 'AI_INTERNAL_ERROR',
        503 => 'AI_SERVICE_UNAVAILABLE',
        504 => 'TIMEOUT',
    ];

    private const SAFE_MESSAGES = [
        'AI_VALIDATION_ERROR'    => 'The AI service rejected the request.',
        'AI_UNAUTHORIZED'        => 'Authentication with the AI service failed.',
        'AI_RATE_LIMITED'        => 'The AI service is rate limited.',
        'AI_INTERNAL_ERROR'      => 'The AI service reported an internal error.',
        'AI_SERVICE_UNAVAILABLE' => 'The AI service is unavailable.',
        'TIMEOUT'                => 'The request to the AI service timed out.',
        'INVALID_AI_RESPONSE'    => 'The AI service returned an invalid response.',
    ];

    public function __construct(private readonly AiHttpTransport $transport) {}

    public function chat(int $userId, string $message, ?string $sessionId = null): array
    {
        $payload = [
            'user_id' => $userId,
            'message' => $message,
        ];

        // Omit session_id entirely when unknown — never send "session_id": null.
        if ($sessionId !== null && $sessionId !== '') {
            $payload['session_id'] = $sessionId;
        }

        $body = $this->post((string) config('chat.ai.chat_path', '/api/chat'), $payload);

        $this->requireUserId($body, $userId);

        if (! $this->isNonEmptyString($body['message'] ?? null)) {
            $this->fail('INVALID_AI_RESPONSE');
        }
        if (! $this->isNonEmptyString($body['session_id'] ?? null)) {
            $this->fail('INVALID_AI_RESPONSE');
        }

        return [
            'message'    => $body['message'],
            'user_id'    => $userId,
            'session_id' => $body['session_id'],
        ];
    }

    public function summarize(int $userId, string $sessionId): array
    {
        $body = $this->post((string) config('chat.ai.summarize_path', '/api/chat/summarize'), [
            'user_id'    => $userId,
            'session_id' => $sessionId,
        ]);

        $status = $body['status'] ?? null;
        if (! in_array($status, ['created', 'updated', 'skipped'], true)) {
            $this->fail('INVALID_AI_RESPONSE');
        }

        $this->requireUserId($body, $userId);

        if (($body['session_id'] ?? null) !== $sessionId) {
            $this->fail('INVALID_AI_RESPONSE');
        }
        if (! is_string($body['summary_preview'] ?? null)) {
            $this->fail('INVALID_AI_RESPONSE');
        }

        $reason = $body['reason'] ?? null;
        if ($reason !== null && ! is_string($reason)) {
            $this->fail('INVALID_AI_RESPONSE');
        }

        return [
            'status'          => $status,
            'session_id'      => $sessionId,
            'user_id'         => $userId,
            'summary_preview' => $body['summary_preview'],
            'reason'          => $reason,
        ];
    }

    /**
     * POST to {base_url}{path}, map non-200 to a safe code, and decode the body.
     *
     * @return array<string, mixed>
     *
     * @throws AiClientException
     */
    private function post(string $path, array $payload): array
    {
        $url   = rtrim((string) config('chat.ai.base_url', ''), '/') . $path;
        $token = (string) config('chat.ai.token', '');

        $response = $this->transport->send($url, $token, $payload);

        $status = $response->status();
        if ($status !== 200) {
            $code = self::STATUS_MAP[$status] ?? 'INVALID_AI_RESPONSE';
            $this->fail($code);
        }

        $body = $this->decode($response);
        if (! is_array($body) || array_is_list($body)) {
            $this->fail('INVALID_AI_RESPONSE');
        }

        return $body;
    }

    private function decode(Response $response): mixed
    {
        return $response->json();
    }

    private function requireUserId(array $body, int $expected): void
    {
        $value = $body['user_id'] ?? null;

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            $this->fail('INVALID_AI_RESPONSE');
        }

        if ((int) $value !== $expected) {
            $this->fail('INVALID_AI_RESPONSE');
        }
    }

    private function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /** @throws AiClientException */
    private function fail(string $code): never
    {
        throw new AiClientException($code, self::SAFE_MESSAGES[$code] ?? self::SAFE_MESSAGES['INVALID_AI_RESPONSE']);
    }
}
