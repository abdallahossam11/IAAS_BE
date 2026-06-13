<?php

namespace Tests\Unit;

use App\Exceptions\AiClientException;
use App\Services\Ai\AiOutboundPayloadValidator;
use Tests\TestCase;

class AiOutboundPayloadValidatorTest extends TestCase
{
    private AiOutboundPayloadValidator $validator;

    private const REQUEST_ID      = 'aabbccdd-0011-2233-4455-667788990000';
    private const CONVERSATION_ID = '11112222-3333-4444-5555-666677778888';
    private const GUEST_REF       = '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08'; // 64 hex chars

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new AiOutboundPayloadValidator();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function validStudentPayload(): array
    {
        return [
            'schema_version'  => '1.0',
            'request_id'      => self::REQUEST_ID,
            'conversation_id' => self::CONVERSATION_ID,
            'user_reference'  => 'student:STU-001',
            'language'        => 'auto',
            'messages'        => [['role' => 'user', 'content' => 'Hello']],
            'student_context' => [
                'student_id'        => 'STU-001',
                'full_name'         => 'Ahmed Ali',
                'email'             => 'ahmed@example.com',
                'faculty_id'        => 1,
                'faculty_name'      => 'Engineering',
                'gpa'               => 3.75,
                'credits_completed' => 90,
                'credits_required'  => 130,
            ],
        ];
    }

    private function validGuestPayload(): array
    {
        return [
            'schema_version'          => '1.0',
            'request_id'              => self::REQUEST_ID,
            'guest_session_reference' => self::GUEST_REF,
            'language'                => 'auto',
            'messages'                => [['role' => 'user', 'content' => 'Hello guest']],
        ];
    }

    private function assertThrowsConfigError(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected AiClientException was not thrown.');
        } catch (AiClientException $e) {
            $this->assertSame('AI_CONFIGURATION_ERROR', $e->errorCode);
            // Message must never contain payload contents
            $this->assertStringNotContainsString('STU-001', $e->getMessage());
            $this->assertStringNotContainsString(self::REQUEST_ID, $e->getMessage());
        }
    }

    // ── Happy paths ──────────────────────────────────────────────────────────

    public function test_valid_student_payload_passes(): void
    {
        $this->validator->validateStudent($this->validStudentPayload());
        $this->addToAssertionCount(1); // no exception = pass
    }

    public function test_valid_guest_payload_passes(): void
    {
        $this->validator->validateGuest($this->validGuestPayload());
        $this->addToAssertionCount(1);
    }

    public function test_faculty_name_null_is_accepted(): void
    {
        $payload = $this->validStudentPayload();
        $payload['student_context']['faculty_name'] = null;

        $this->validator->validateStudent($payload);
        $this->addToAssertionCount(1);
    }

    public function test_gpa_as_integer_is_accepted(): void
    {
        $payload = $this->validStudentPayload();
        $payload['student_context']['gpa'] = 3; // int counts as numeric

        $this->validator->validateStudent($payload);
        $this->addToAssertionCount(1);
    }

    // ── schema_version ────────────────────────────────────────────────────────

    public function test_wrong_schema_version_rejected(): void
    {
        $payload                    = $this->validStudentPayload();
        $payload['schema_version']  = '2.0';

        $this->assertThrowsConfigError(fn () => $this->validator->validateStudent($payload));
    }

    public function test_missing_schema_version_rejected(): void
    {
        $payload = $this->validStudentPayload();
        unset($payload['schema_version']);

        $this->assertThrowsConfigError(fn () => $this->validator->validateStudent($payload));
    }

    // ── request_id ───────────────────────────────────────────────────────────

    public function test_invalid_request_id_rejected(): void
    {
        $payload               = $this->validStudentPayload();
        $payload['request_id'] = 'not-a-uuid';

        $this->assertThrowsConfigError(fn () => $this->validator->validateStudent($payload));
    }

    // ── language ─────────────────────────────────────────────────────────────

    public function test_wrong_language_rejected(): void
    {
        $payload             = $this->validStudentPayload();
        $payload['language'] = 'ar';

        $this->assertThrowsConfigError(fn () => $this->validator->validateStudent($payload));
    }

    // ── user_reference ───────────────────────────────────────────────────────

    public function test_user_reference_without_student_prefix_rejected(): void
    {
        $payload                   = $this->validStudentPayload();
        $payload['user_reference'] = 'admin:001';

        $this->assertThrowsConfigError(fn () => $this->validator->validateStudent($payload));
    }

    // ── guest_session_reference ──────────────────────────────────────────────

    public function test_invalid_guest_session_reference_rejected(): void
    {
        $payload                              = $this->validGuestPayload();
        $payload['guest_session_reference']   = 'not-a-sha256-hash';

        $this->assertThrowsConfigError(fn () => $this->validator->validateGuest($payload));
    }

    public function test_uppercase_guest_session_reference_rejected(): void
    {
        $payload = $this->validGuestPayload();
        $payload['guest_session_reference'] = strtoupper(self::GUEST_REF); // must be lowercase

        $this->assertThrowsConfigError(fn () => $this->validator->validateGuest($payload));
    }

    // ── Exact key-set enforcement ─────────────────────────────────────────────

    public function test_unexpected_student_top_level_field_rejected(): void
    {
        $payload                = $this->validStudentPayload();
        $payload['extra_field'] = 'value';

        $this->assertThrowsConfigError(fn () => $this->validator->validateStudent($payload));
    }

    public function test_unexpected_guest_top_level_field_rejected(): void
    {
        $payload                = $this->validGuestPayload();
        $payload['extra_field'] = 'value';

        $this->assertThrowsConfigError(fn () => $this->validator->validateGuest($payload));
    }

    public function test_unexpected_student_context_field_rejected(): void
    {
        $payload = $this->validStudentPayload();
        $payload['student_context']['password'] = 'should_never_be_here';

        $this->assertThrowsConfigError(fn () => $this->validator->validateStudent($payload));
    }

    public function test_unexpected_message_field_rejected(): void
    {
        $payload = $this->validStudentPayload();
        $payload['messages'] = [['role' => 'user', 'content' => 'Hello', 'timestamp' => 123]];

        $this->assertThrowsConfigError(fn () => $this->validator->validateStudent($payload));
    }

    // ── Messages validation ───────────────────────────────────────────────────

    public function test_empty_messages_list_rejected(): void
    {
        $payload             = $this->validStudentPayload();
        $payload['messages'] = [];

        $this->assertThrowsConfigError(fn () => $this->validator->validateStudent($payload));
    }

    public function test_invalid_message_role_rejected(): void
    {
        $payload             = $this->validStudentPayload();
        $payload['messages'] = [['role' => 'system', 'content' => 'You are helpful']];

        $this->assertThrowsConfigError(fn () => $this->validator->validateStudent($payload));
    }

    public function test_whitespace_only_message_content_rejected(): void
    {
        $payload             = $this->validStudentPayload();
        $payload['messages'] = [['role' => 'user', 'content' => '   ']];

        $this->assertThrowsConfigError(fn () => $this->validator->validateStudent($payload));
    }

    public function test_empty_message_content_rejected(): void
    {
        $payload             = $this->validStudentPayload();
        $payload['messages'] = [['role' => 'user', 'content' => '']];

        $this->assertThrowsConfigError(fn () => $this->validator->validateStudent($payload));
    }

    // ── student_context field types ───────────────────────────────────────────

    public function test_non_integer_faculty_id_rejected(): void
    {
        $payload = $this->validStudentPayload();
        $payload['student_context']['faculty_id'] = '1'; // string, not int

        $this->assertThrowsConfigError(fn () => $this->validator->validateStudent($payload));
    }

    public function test_invalid_email_rejected(): void
    {
        $payload = $this->validStudentPayload();
        $payload['student_context']['email'] = 'not-an-email';

        $this->assertThrowsConfigError(fn () => $this->validator->validateStudent($payload));
    }

    public function test_string_gpa_rejected(): void
    {
        $payload = $this->validStudentPayload();
        $payload['student_context']['gpa'] = '3.75'; // string, not numeric

        $this->assertThrowsConfigError(fn () => $this->validator->validateStudent($payload));
    }

    public function test_non_integer_credits_completed_rejected(): void
    {
        $payload = $this->validStudentPayload();
        $payload['student_context']['credits_completed'] = 90.5; // float, not int

        $this->assertThrowsConfigError(fn () => $this->validator->validateStudent($payload));
    }

    // ── messages must be a real JSON array (array_is_list) ───────────────────

    public function test_associative_messages_array_is_rejected(): void
    {
        $payload             = $this->validStudentPayload();
        $payload['messages'] = ['first' => ['role' => 'user', 'content' => 'Hello']];

        $this->assertThrowsConfigError(fn () => $this->validator->validateStudent($payload));
    }

    public function test_messages_with_non_sequential_numeric_keys_rejected(): void
    {
        // Keys 0 and 2 — not a list (gap at 1). array_filter() can produce this pattern.
        $payload             = $this->validStudentPayload();
        $payload['messages'] = [
            0 => ['role' => 'user',      'content' => 'Hello'],
            2 => ['role' => 'assistant', 'content' => 'Hi'],
        ];

        $this->assertThrowsConfigError(fn () => $this->validator->validateStudent($payload));
    }

    // ── user_reference must match student_context.student_id exactly ──────────

    public function test_user_reference_with_empty_student_suffix_rejected(): void
    {
        $payload                   = $this->validStudentPayload();
        $payload['user_reference'] = 'student:'; // prefix only, no ID

        $this->assertThrowsConfigError(fn () => $this->validator->validateStudent($payload));
    }

    public function test_user_reference_with_mismatched_student_id_rejected(): void
    {
        $payload                   = $this->validStudentPayload();
        $payload['user_reference'] = 'student:DIFFERENT-ID'; // student_context.student_id is STU-001

        $this->assertThrowsConfigError(fn () => $this->validator->validateStudent($payload));
    }

    public function test_user_reference_matching_student_context_student_id_passes(): void
    {
        $payload = $this->validStudentPayload();
        $payload['user_reference']                   = 'student:ALT-999';
        $payload['student_context']['student_id']    = 'ALT-999';

        $this->validator->validateStudent($payload);
        $this->addToAssertionCount(1);
    }

    // ── Error messages must not leak payload contents ─────────────────────────

    public function test_error_message_does_not_contain_payload_values(): void
    {
        $payload = $this->validStudentPayload();
        unset($payload['schema_version']); // trigger failure

        try {
            $this->validator->validateStudent($payload);
            $this->fail('Expected AiClientException was not thrown.');
        } catch (AiClientException $e) {
            $this->assertStringNotContainsString('STU-001', $e->getMessage());
            $this->assertStringNotContainsString('ahmed@example.com', $e->getMessage());
            $this->assertStringNotContainsString(self::REQUEST_ID, $e->getMessage());
        }
    }
}
