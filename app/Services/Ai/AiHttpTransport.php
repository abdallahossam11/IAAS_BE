<?php

namespace App\Services\Ai;

use App\Exceptions\AiClientException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class AiHttpTransport
{
    private const ERR_CONFIG = 'AI_CONFIGURATION_ERROR';

    /**
     * Send the payload to the AI service and return the raw response.
     *
     * URL and token are trimmed here so the normalised values are used for
     * both the guard and the actual request. Catches only ConnectionException
     * — programming defects surface as real exceptions. Real timeout behaviour
     * remains an integration-test item.
     *
     * @throws AiClientException on configuration error or transport failure
     */
    public function send(string $fullUrl, string $token, array $payload): Response
    {
        $fullUrl = trim($fullUrl);
        $token   = trim($token);

        $this->guardUrlAndToken($fullUrl, $token);

        try {
            return Http::acceptJson()
                ->asJson()
                ->contentType('application/json; charset=utf-8')
                ->withToken($token)
                ->withHeaders(['X-Request-ID' => $payload['request_id']])
                ->connectTimeout((int) config('chat.ai_connect_timeout', 10))
                ->timeout((int) config('chat.ai_response_timeout', 420))
                ->withoutRedirecting()
                ->post($fullUrl, $payload);
        } catch (ConnectionException $e) {
            $msg = strtolower($e->getMessage());

            // Best-effort timeout detection — cURL error 28 or timeout message keywords.
            // Real timeout enforcement is verified only in integration tests.
            if (str_contains($msg, 'timed out') || str_contains($msg, 'timeout') || $e->getCode() === 28) {
                throw new AiClientException('TIMEOUT', 'The request to the AI service timed out.');
            }

            throw new AiClientException('AI_CONNECTION_ERROR', 'Could not connect to the AI service.');
        }
    }

    /**
     * @throws AiClientException with AI_CONFIGURATION_ERROR for any validation failure.
     *
     * Receives already-trimmed $url and $token from send(). The URL, token,
     * Authorization header, and payload are NEVER included in exception messages.
     */
    private function guardUrlAndToken(string $url, string $token): void
    {
        if ($url === '') {
            throw new AiClientException(self::ERR_CONFIG, 'The AI service URL is not configured.');
        }

        if ($token === '') {
            throw new AiClientException(self::ERR_CONFIG, 'The AI service token is not configured.');
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new AiClientException(self::ERR_CONFIG, 'The AI service URL is not a valid URL.');
        }

        $parts = parse_url($url);

        if (! is_array($parts) || empty($parts['host'])) {
            throw new AiClientException(self::ERR_CONFIG, 'The AI service URL is missing a host.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new AiClientException(self::ERR_CONFIG, 'The AI service URL must not contain credentials.');
        }

        if (isset($parts['fragment'])) {
            throw new AiClientException(self::ERR_CONFIG, 'The AI service URL must not contain a fragment.');
        }

        if (isset($parts['query'])) {
            throw new AiClientException(self::ERR_CONFIG, 'The AI service URL must not contain a query string.');
        }

        $scheme = strtolower($parts['scheme'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new AiClientException(self::ERR_CONFIG, 'The AI service URL must use http or https.');
        }

        if ($scheme !== 'https' && ! app()->environment(['local', 'testing'])) {
            throw new AiClientException(
                self::ERR_CONFIG,
                'The AI service URL must use HTTPS outside local and testing environments.',
            );
        }
    }
}
