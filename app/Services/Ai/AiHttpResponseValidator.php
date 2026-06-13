<?php

namespace App\Services\Ai;

use App\Exceptions\AiClientException;
use Illuminate\Http\Client\Response;

class AiHttpResponseValidator
{
    private const INVALID     = 'INVALID_AI_RESPONSE';
    private const INVALID_MSG = 'The AI service returned an unexpected response.';

    public function __construct(private readonly AiHttpErrorMapper $errorMapper) {}

    /**
     * @return array{content: string}
     * @throws AiClientException
     */
    public function validate(Response $response, string $outboundRequestId): array
    {
        $status = $response->status();

        if ($status === 200) {
            return $this->validateSuccess($response, $outboundRequestId);
        }

        if ($this->errorMapper->isFrozenErrorStatus($status)) {
            $body = $this->decodeJsonBody($response);
            $this->errorMapper->map($status, $body, $outboundRequestId); // always throws
        }

        // Any other status: 201, 202, 204, 3xx, 404, 502, etc. — including unfollowed redirects
        throw new AiClientException(self::INVALID, self::INVALID_MSG);
    }

    /**
     * @return array{content: string}
     */
    private function validateSuccess(Response $response, string $outboundRequestId): array
    {
        $body = $this->decodeJsonBody($response);

        // Exact key set: content, request_id, schema_version, status
        if (! $this->hasExactKeys($body, ['content', 'request_id', 'schema_version', 'status'])) {
            throw new AiClientException(self::INVALID, self::INVALID_MSG);
        }

        if ($body['schema_version'] !== '1.0') {
            throw new AiClientException(self::INVALID, self::INVALID_MSG);
        }

        if (! $this->isUuid($body['request_id'])) {
            throw new AiClientException(self::INVALID, self::INVALID_MSG);
        }

        if (strtolower($body['request_id']) !== strtolower($outboundRequestId)) {
            throw new AiClientException(self::INVALID, self::INVALID_MSG);
        }

        if ($body['status'] !== 'completed') {
            throw new AiClientException(self::INVALID, self::INVALID_MSG);
        }

        if (! is_string($body['content']) || trim($body['content']) === '') {
            throw new AiClientException(self::INVALID, self::INVALID_MSG);
        }

        return ['content' => $body['content']];
    }

    /**
     * Decode the response body as a JSON associative array.
     * Scalars, non-JSON, and JSON arrays all throw INVALID_AI_RESPONSE.
     */
    private function decodeJsonBody(Response $response): array
    {
        $decoded = $response->json();

        if (! is_array($decoded)) {
            // Covers: null (invalid JSON / empty body), strings, integers, booleans
            throw new AiClientException(self::INVALID, self::INVALID_MSG);
        }

        return $decoded;
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
