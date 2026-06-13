<?php

namespace App\Services\Ai;

use App\Exceptions\AiClientException;

class AiHttpErrorMapper
{
    private const STATUS_MAP = [
        400 => ['remote' => 'INVALID_REQUEST',    'internal' => 'AI_INVALID_REQUEST',     'retryable' => false],
        401 => ['remote' => 'UNAUTHORIZED',        'internal' => 'AI_UNAUTHORIZED',         'retryable' => false],
        409 => ['remote' => 'REQUEST_ID_CONFLICT', 'internal' => 'AI_REQUEST_ID_CONFLICT',  'retryable' => false],
        422 => ['remote' => 'VALIDATION_ERROR',    'internal' => 'AI_VALIDATION_ERROR',     'retryable' => false],
        429 => ['remote' => 'RATE_LIMITED',        'internal' => 'AI_RATE_LIMITED',         'retryable' => true],
        500 => ['remote' => 'INTERNAL_ERROR',      'internal' => 'AI_INTERNAL_ERROR',       'retryable' => false],
        503 => ['remote' => 'SERVICE_UNAVAILABLE', 'internal' => 'AI_SERVICE_UNAVAILABLE',  'retryable' => true],
        504 => ['remote' => 'AI_TIMEOUT',          'internal' => 'TIMEOUT',                 'retryable' => true],
    ];

    private const SAFE_MESSAGES = [
        'AI_INVALID_REQUEST'     => 'The AI service rejected the request as invalid.',
        'AI_UNAUTHORIZED'        => 'Authentication with the AI service failed.',
        'AI_REQUEST_ID_CONFLICT' => 'The AI service detected a request identifier conflict.',
        'AI_VALIDATION_ERROR'    => 'The AI service rejected the request due to a validation error.',
        'AI_RATE_LIMITED'        => 'The AI service is rate-limiting requests.',
        'AI_INTERNAL_ERROR'      => 'The AI service reported an internal error.',
        'AI_SERVICE_UNAVAILABLE' => 'The AI service is temporarily unavailable.',
        'TIMEOUT'                => 'The AI service reported a processing timeout.',
        'INVALID_AI_RESPONSE'    => 'The AI service returned an unexpected response.',
    ];

    public function isFrozenErrorStatus(int $status): bool
    {
        return isset(self::STATUS_MAP[$status]);
    }

    /**
     * Validate the error envelope and throw with a local safe message.
     *
     * Remote error.message is NEVER used as the AiClientException message.
     *
     * @throws AiClientException always
     */
    public function map(int $status, array $body, string $outboundRequestId): never
    {
        $mapping = self::STATUS_MAP[$status] ?? null;

        if ($mapping === null) {
            throw new AiClientException(
                'INVALID_AI_RESPONSE',
                self::SAFE_MESSAGES['INVALID_AI_RESPONSE'],
            );
        }

        // Exact top-level key set: schema_version, request_id, error
        if (! $this->hasExactKeys($body, ['error', 'request_id', 'schema_version'])) {
            throw new AiClientException(
                'INVALID_AI_RESPONSE',
                self::SAFE_MESSAGES['INVALID_AI_RESPONSE'],
            );
        }

        if ($body['schema_version'] !== '1.0') {
            throw new AiClientException(
                'INVALID_AI_RESPONSE',
                self::SAFE_MESSAGES['INVALID_AI_RESPONSE'],
            );
        }

        // request_id: null or a UUID matching the outbound request_id
        $rid = $body['request_id'];
        if ($rid !== null) {
            if (! $this->isUuid($rid) || strtolower($rid) !== strtolower($outboundRequestId)) {
                throw new AiClientException(
                    'INVALID_AI_RESPONSE',
                    self::SAFE_MESSAGES['INVALID_AI_RESPONSE'],
                );
            }
        }

        if (! is_array($body['error'])) {
            throw new AiClientException(
                'INVALID_AI_RESPONSE',
                self::SAFE_MESSAGES['INVALID_AI_RESPONSE'],
            );
        }

        // Exact nested error key set: code, message, retryable
        if (! $this->hasExactKeys($body['error'], ['code', 'message', 'retryable'])) {
            throw new AiClientException(
                'INVALID_AI_RESPONSE',
                self::SAFE_MESSAGES['INVALID_AI_RESPONSE'],
            );
        }

        $error = $body['error'];

        // code must match the frozen mapping for this HTTP status
        if ($error['code'] !== $mapping['remote']) {
            throw new AiClientException(
                'INVALID_AI_RESPONSE',
                self::SAFE_MESSAGES['INVALID_AI_RESPONSE'],
            );
        }

        // message must be a trimmed non-empty string
        if (! is_string($error['message']) || trim($error['message']) === '') {
            throw new AiClientException(
                'INVALID_AI_RESPONSE',
                self::SAFE_MESSAGES['INVALID_AI_RESPONSE'],
            );
        }

        // retryable must be boolean and match the frozen mapping
        if (! is_bool($error['retryable']) || $error['retryable'] !== $mapping['retryable']) {
            throw new AiClientException(
                'INVALID_AI_RESPONSE',
                self::SAFE_MESSAGES['INVALID_AI_RESPONSE'],
            );
        }

        $internalCode = $mapping['internal'];

        throw new AiClientException(
            $internalCode,
            self::SAFE_MESSAGES[$internalCode] ?? self::SAFE_MESSAGES['INVALID_AI_RESPONSE'],
        );
    }

    private function hasExactKeys(array $array, array $expected): bool
    {
        $actual = array_keys($array);
        sort($actual);
        sort($expected);

        return $actual === $expected;
    }

    private function isUuid(mixed $value): bool
    {
        return is_string($value)
            && (bool) preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                $value,
            );
    }
}
