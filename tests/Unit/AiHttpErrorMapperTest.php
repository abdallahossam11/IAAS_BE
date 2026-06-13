<?php

namespace Tests\Unit;

use App\Exceptions\AiClientException;
use App\Services\Ai\AiHttpErrorMapper;
use Tests\TestCase;

class AiHttpErrorMapperTest extends TestCase
{
    private AiHttpErrorMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new AiHttpErrorMapper();
    }

    private function validEnvelope(int $status, string $code, bool $retryable): array
    {
        return [
            'schema_version' => '1.0',
            'request_id'     => 'aabbccdd-0011-2233-4455-667788990000',
            'error'          => [
                'code'      => $code,
                'message'   => 'A safe human-readable message from the AI service.',
                'retryable' => $retryable,
            ],
        ];
    }

    // ── Frozen status detection ──────────────────────────────────────────────

    public function test_frozen_statuses_are_recognised(): void
    {
        foreach ([400, 401, 409, 422, 429, 500, 503, 504] as $status) {
            $this->assertTrue($this->mapper->isFrozenErrorStatus($status));
        }
    }

    public function test_non_frozen_statuses_are_not_recognised(): void
    {
        foreach ([200, 201, 202, 204, 301, 302, 404, 502] as $status) {
            $this->assertFalse($this->mapper->isFrozenErrorStatus($status));
        }
    }

    // ── Correct internal code per frozen status ──────────────────────────────

    /** @dataProvider frozenStatusProvider */
    public function test_frozen_error_envelope_maps_to_correct_internal_code(
        int    $status,
        string $remoteCode,
        string $internalCode,
        bool   $retryable,
    ): void {
        $body = $this->validEnvelope($status, $remoteCode, $retryable);

        try {
            $this->mapper->map($status, $body, 'aabbccdd-0011-2233-4455-667788990000');
            $this->fail('Expected AiClientException was not thrown.');
        } catch (AiClientException $e) {
            $this->assertSame($internalCode, $e->errorCode);
        }
    }

    public static function frozenStatusProvider(): array
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

    // ── Remote error.message is NEVER exposed ────────────────────────────────

    public function test_remote_error_message_never_used_as_exception_message(): void
    {
        $remoteMessage = 'This is a secret remote error detail that must not leak to users.';
        $body = [
            'schema_version' => '1.0',
            'request_id'     => 'aabbccdd-0011-2233-4455-667788990000',
            'error'          => [
                'code'      => 'UNAUTHORIZED',
                'message'   => $remoteMessage,
                'retryable' => false,
            ],
        ];

        try {
            $this->mapper->map(401, $body, 'aabbccdd-0011-2233-4455-667788990000');
            $this->fail('Expected AiClientException was not thrown.');
        } catch (AiClientException $e) {
            $this->assertStringNotContainsString($remoteMessage, $e->getMessage());
            $this->assertNotEmpty($e->getMessage());
        }
    }

    // ── Envelope validation — extra / missing keys ───────────────────────────

    public function test_extra_top_level_field_throws_invalid_response(): void
    {
        $body = $this->validEnvelope(401, 'UNAUTHORIZED', false);
        $body['extra_field'] = 'unexpected';

        $this->assertThrowsInvalidResponse(fn () => $this->mapper->map(401, $body, 'aabbccdd-0011-2233-4455-667788990000'));
    }

    public function test_missing_top_level_field_throws_invalid_response(): void
    {
        $body = $this->validEnvelope(401, 'UNAUTHORIZED', false);
        unset($body['schema_version']);

        $this->assertThrowsInvalidResponse(fn () => $this->mapper->map(401, $body, 'aabbccdd-0011-2233-4455-667788990000'));
    }

    public function test_extra_nested_error_field_throws_invalid_response(): void
    {
        $body = $this->validEnvelope(401, 'UNAUTHORIZED', false);
        $body['error']['debug_info'] = 'internal path /var/www/secret';

        $this->assertThrowsInvalidResponse(fn () => $this->mapper->map(401, $body, 'aabbccdd-0011-2233-4455-667788990000'));
    }

    public function test_missing_nested_error_field_throws_invalid_response(): void
    {
        $body = $this->validEnvelope(401, 'UNAUTHORIZED', false);
        unset($body['error']['retryable']);

        $this->assertThrowsInvalidResponse(fn () => $this->mapper->map(401, $body, 'aabbccdd-0011-2233-4455-667788990000'));
    }

    // ── schema_version validation ─────────────────────────────────────────────

    public function test_wrong_schema_version_throws_invalid_response(): void
    {
        $body = $this->validEnvelope(401, 'UNAUTHORIZED', false);
        $body['schema_version'] = '2.0';

        $this->assertThrowsInvalidResponse(fn () => $this->mapper->map(401, $body, 'aabbccdd-0011-2233-4455-667788990000'));
    }

    // ── request_id validation ────────────────────────────────────────────────

    public function test_null_request_id_is_accepted(): void
    {
        $body = $this->validEnvelope(401, 'UNAUTHORIZED', false);
        $body['request_id'] = null;

        try {
            $this->mapper->map(401, $body, 'aabbccdd-0011-2233-4455-667788990000');
            $this->fail('Expected AiClientException was not thrown.');
        } catch (AiClientException $e) {
            $this->assertSame('AI_UNAUTHORIZED', $e->errorCode);
        }
    }

    public function test_mismatched_request_id_throws_invalid_response(): void
    {
        $body = $this->validEnvelope(401, 'UNAUTHORIZED', false);
        $body['request_id'] = '00000000-0000-0000-0000-000000000001'; // different UUID

        $this->assertThrowsInvalidResponse(fn () => $this->mapper->map(401, $body, 'aabbccdd-0011-2233-4455-667788990000'));
    }

    public function test_non_uuid_request_id_throws_invalid_response(): void
    {
        $body = $this->validEnvelope(401, 'UNAUTHORIZED', false);
        $body['request_id'] = 'not-a-uuid';

        $this->assertThrowsInvalidResponse(fn () => $this->mapper->map(401, $body, 'aabbccdd-0011-2233-4455-667788990000'));
    }

    // ── code/status pairing ──────────────────────────────────────────────────

    public function test_wrong_code_for_status_throws_invalid_response(): void
    {
        // Status 400 expects INVALID_REQUEST, not UNAUTHORIZED
        $body = $this->validEnvelope(400, 'UNAUTHORIZED', false);

        $this->assertThrowsInvalidResponse(fn () => $this->mapper->map(400, $body, 'aabbccdd-0011-2233-4455-667788990000'));
    }

    // ── retryable validation ─────────────────────────────────────────────────

    public function test_wrong_retryable_value_throws_invalid_response(): void
    {
        // Status 401 expects retryable=false; pass retryable=true
        $body = $this->validEnvelope(401, 'UNAUTHORIZED', true);

        $this->assertThrowsInvalidResponse(fn () => $this->mapper->map(401, $body, 'aabbccdd-0011-2233-4455-667788990000'));
    }

    public function test_non_boolean_retryable_throws_invalid_response(): void
    {
        $body = $this->validEnvelope(401, 'UNAUTHORIZED', false);
        $body['error']['retryable'] = 'false'; // string instead of bool

        $this->assertThrowsInvalidResponse(fn () => $this->mapper->map(401, $body, 'aabbccdd-0011-2233-4455-667788990000'));
    }

    // ── error.message validation ─────────────────────────────────────────────

    public function test_whitespace_only_message_throws_invalid_response(): void
    {
        $body = $this->validEnvelope(401, 'UNAUTHORIZED', false);
        $body['error']['message'] = '   ';

        $this->assertThrowsInvalidResponse(fn () => $this->mapper->map(401, $body, 'aabbccdd-0011-2233-4455-667788990000'));
    }

    public function test_empty_message_throws_invalid_response(): void
    {
        $body = $this->validEnvelope(401, 'UNAUTHORIZED', false);
        $body['error']['message'] = '';

        $this->assertThrowsInvalidResponse(fn () => $this->mapper->map(401, $body, 'aabbccdd-0011-2233-4455-667788990000'));
    }

    // ── Unknown status ───────────────────────────────────────────────────────

    public function test_unknown_status_throws_invalid_response(): void
    {
        $body = $this->validEnvelope(404, 'INVALID_REQUEST', false);

        $this->assertThrowsInvalidResponse(fn () => $this->mapper->map(404, $body, 'aabbccdd-0011-2233-4455-667788990000'));
    }

    // ── Helper ───────────────────────────────────────────────────────────────

    private function assertThrowsInvalidResponse(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected AiClientException was not thrown.');
        } catch (AiClientException $e) {
            $this->assertSame('INVALID_AI_RESPONSE', $e->errorCode);
        }
    }
}
