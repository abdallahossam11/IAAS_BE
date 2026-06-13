<?php

namespace Tests\Unit;

use App\Exceptions\AiClientException;
use App\Services\Ai\AiHttpErrorMapper;
use App\Services\Ai\AiHttpResponseValidator;
use Illuminate\Http\Client\Response;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Tests\TestCase;

class AiHttpResponseValidatorTest extends TestCase
{
    private AiHttpResponseValidator $validator;

    private const REQUEST_ID  = 'aabbccdd-0011-2233-4455-667788990000';
    private const OTHER_UUID  = '00000000-0000-0000-0000-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new AiHttpResponseValidator(new AiHttpErrorMapper());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeResponse(mixed $body, int $status = 200, array $headers = []): Response
    {
        $bodyString = is_array($body) ? json_encode($body) : (string) $body;
        $guzzle     = new GuzzleResponse(
            $status,
            array_merge(['Content-Type' => 'application/json'], $headers),
            $bodyString,
        );

        return new Response($guzzle);
    }

    private function validSuccess(): array
    {
        return [
            'schema_version' => '1.0',
            'request_id'     => self::REQUEST_ID,
            'status'         => 'completed',
            'content'        => 'AI-generated answer.',
        ];
    }

    private function validErrorEnvelope(int $status, string $code, bool $retryable): array
    {
        return [
            'schema_version' => '1.0',
            'request_id'     => self::REQUEST_ID,
            'error'          => [
                'code'      => $code,
                'message'   => 'Safe human-readable message.',
                'retryable' => $retryable,
            ],
        ];
    }

    private function assertThrowsInvalidResponse(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected AiClientException was not thrown.');
        } catch (AiClientException $e) {
            $this->assertSame('INVALID_AI_RESPONSE', $e->errorCode);
        }
    }

    // ── Happy path (HTTP 200) ─────────────────────────────────────────────────

    public function test_valid_200_response_returns_content(): void
    {
        $response = $this->makeResponse($this->validSuccess());
        $result   = $this->validator->validate($response, self::REQUEST_ID);

        $this->assertSame(['content' => 'AI-generated answer.'], $result);
    }

    // ── Success envelope — key set ────────────────────────────────────────────

    public function test_extra_success_field_throws_invalid_response(): void
    {
        $body              = $this->validSuccess();
        $body['extra_key'] = 'unexpected';

        $this->assertThrowsInvalidResponse(
            fn () => $this->validator->validate($this->makeResponse($body), self::REQUEST_ID),
        );
    }

    public function test_missing_success_field_throws_invalid_response(): void
    {
        $body = $this->validSuccess();
        unset($body['status']);

        $this->assertThrowsInvalidResponse(
            fn () => $this->validator->validate($this->makeResponse($body), self::REQUEST_ID),
        );
    }

    // ── schema_version ────────────────────────────────────────────────────────

    public function test_wrong_schema_version_in_success_throws_invalid_response(): void
    {
        $body                    = $this->validSuccess();
        $body['schema_version']  = '2.0';

        $this->assertThrowsInvalidResponse(
            fn () => $this->validator->validate($this->makeResponse($body), self::REQUEST_ID),
        );
    }

    // ── request_id match ─────────────────────────────────────────────────────

    public function test_mismatched_request_id_in_success_throws_invalid_response(): void
    {
        $body               = $this->validSuccess();
        $body['request_id'] = self::OTHER_UUID;

        $this->assertThrowsInvalidResponse(
            fn () => $this->validator->validate($this->makeResponse($body), self::REQUEST_ID),
        );
    }

    public function test_non_uuid_request_id_in_success_throws_invalid_response(): void
    {
        $body               = $this->validSuccess();
        $body['request_id'] = 'not-a-uuid';

        $this->assertThrowsInvalidResponse(
            fn () => $this->validator->validate($this->makeResponse($body), self::REQUEST_ID),
        );
    }

    // ── status field ─────────────────────────────────────────────────────────

    public function test_wrong_success_status_throws_invalid_response(): void
    {
        $body           = $this->validSuccess();
        $body['status'] = 'processing';

        $this->assertThrowsInvalidResponse(
            fn () => $this->validator->validate($this->makeResponse($body), self::REQUEST_ID),
        );
    }

    // ── content field ────────────────────────────────────────────────────────

    public function test_missing_content_throws_invalid_response(): void
    {
        $body = $this->validSuccess();
        unset($body['content']);
        // Replace with another valid key to keep key count the same… actually just remove it
        // (will fail key check as 'content' is required)

        $this->assertThrowsInvalidResponse(
            fn () => $this->validator->validate($this->makeResponse($body), self::REQUEST_ID),
        );
    }

    public function test_non_string_content_throws_invalid_response(): void
    {
        $body            = $this->validSuccess();
        $body['content'] = 42;

        $this->assertThrowsInvalidResponse(
            fn () => $this->validator->validate($this->makeResponse($body), self::REQUEST_ID),
        );
    }

    public function test_empty_content_throws_invalid_response(): void
    {
        $body            = $this->validSuccess();
        $body['content'] = '';

        $this->assertThrowsInvalidResponse(
            fn () => $this->validator->validate($this->makeResponse($body), self::REQUEST_ID),
        );
    }

    public function test_whitespace_only_content_throws_invalid_response(): void
    {
        $body            = $this->validSuccess();
        $body['content'] = '   ';

        $this->assertThrowsInvalidResponse(
            fn () => $this->validator->validate($this->makeResponse($body), self::REQUEST_ID),
        );
    }

    // ── Body shape ────────────────────────────────────────────────────────────

    public function test_invalid_json_body_throws_invalid_response(): void
    {
        $response = $this->makeResponse('this is not valid JSON');

        $this->assertThrowsInvalidResponse(
            fn () => $this->validator->validate($response, self::REQUEST_ID),
        );
    }

    public function test_json_array_body_throws_invalid_response(): void
    {
        // A top-level JSON array [1,2,3] decodes to a numerically-keyed PHP array;
        // key validation fails → INVALID_AI_RESPONSE
        $response = $this->makeResponse('[1,2,3]');

        $this->assertThrowsInvalidResponse(
            fn () => $this->validator->validate($response, self::REQUEST_ID),
        );
    }

    public function test_json_scalar_body_throws_invalid_response(): void
    {
        $response = $this->makeResponse('"just a string"');

        $this->assertThrowsInvalidResponse(
            fn () => $this->validator->validate($response, self::REQUEST_ID),
        );
    }

    // ── Non-200, non-frozen statuses all throw INVALID_AI_RESPONSE ───────────

    /** @dataProvider nonFrozenNon200StatusProvider */
    public function test_non_frozen_non_200_status_throws_invalid_response(int $status): void
    {
        $response = $this->makeResponse($this->validSuccess(), $status);

        $this->assertThrowsInvalidResponse(
            fn () => $this->validator->validate($response, self::REQUEST_ID),
        );
    }

    public static function nonFrozenNon200StatusProvider(): array
    {
        return [
            'HTTP 201' => [201],
            'HTTP 202' => [202],
            'HTTP 204' => [204],
            'HTTP 301' => [301],
            'HTTP 302' => [302],
            'HTTP 404' => [404],
            'HTTP 502' => [502],
        ];
    }

    // ── Frozen error statuses → correct internal code via mapper ─────────────

    /** @dataProvider frozenErrorStatusProvider */
    public function test_frozen_error_status_with_valid_envelope_throws_mapped_code(
        int    $status,
        string $remoteCode,
        string $internalCode,
        bool   $retryable,
    ): void {
        $body     = $this->validErrorEnvelope($status, $remoteCode, $retryable);
        $response = $this->makeResponse($body, $status);

        try {
            $this->validator->validate($response, self::REQUEST_ID);
            $this->fail('Expected AiClientException was not thrown.');
        } catch (AiClientException $e) {
            $this->assertSame($internalCode, $e->errorCode);
        }
    }

    public static function frozenErrorStatusProvider(): array
    {
        return [
            [400, 'INVALID_REQUEST',    'AI_INVALID_REQUEST',    false],
            [401, 'UNAUTHORIZED',        'AI_UNAUTHORIZED',        false],
            [409, 'REQUEST_ID_CONFLICT', 'AI_REQUEST_ID_CONFLICT', false],
            [422, 'VALIDATION_ERROR',    'AI_VALIDATION_ERROR',    false],
            [429, 'RATE_LIMITED',        'AI_RATE_LIMITED',        true],
            [500, 'INTERNAL_ERROR',      'AI_INTERNAL_ERROR',      false],
            [503, 'SERVICE_UNAVAILABLE', 'AI_SERVICE_UNAVAILABLE', true],
            [504, 'AI_TIMEOUT',          'TIMEOUT',                true],
        ];
    }

    // ── Frozen error status with malformed envelope → INVALID_AI_RESPONSE ────

    public function test_frozen_error_status_with_malformed_envelope_throws_invalid_response(): void
    {
        // 400 with wrong JSON structure
        $response = $this->makeResponse(['unexpected' => 'shape'], 400);

        $this->assertThrowsInvalidResponse(
            fn () => $this->validator->validate($response, self::REQUEST_ID),
        );
    }
}
