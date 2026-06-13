<?php

namespace App\Services\Ai;

use App\Exceptions\AiClientException;

class AiOutboundPayloadValidator
{
    private const ERR_CODE = 'AI_CONFIGURATION_ERROR';
    private const ERR_MSG  = 'The AI request payload is not configured correctly.';

    private const STUDENT_TOP_KEYS = [
        'conversation_id', 'language', 'messages',
        'request_id', 'schema_version', 'student_context', 'user_reference',
    ];

    private const STUDENT_CONTEXT_KEYS = [
        'credits_completed', 'credits_required', 'email',
        'faculty_id', 'faculty_name', 'full_name', 'gpa', 'student_id',
    ];

    private const GUEST_TOP_KEYS = [
        'guest_session_reference', 'language', 'messages', 'request_id', 'schema_version',
    ];

    private const MESSAGE_KEYS = ['content', 'role'];

    /**
     * @throws AiClientException
     */
    public function validateStudent(array $payload): void
    {
        $this->requireExactKeys($payload, self::STUDENT_TOP_KEYS);

        $this->requireStringValue($payload['schema_version'] ?? null, '1.0');
        $this->requireUuid($payload['request_id'] ?? null);
        $this->requireUuid($payload['conversation_id'] ?? null);
        $this->requireStringValue($payload['language'] ?? null, 'auto');
        $this->requireMessages($payload['messages'] ?? null);

        // student_context validated first so student_id is available for user_reference check
        $this->requireStudentContext($payload['student_context'] ?? null);
        $this->requireUserReference(
            $payload['user_reference'] ?? null,
            $payload['student_context']['student_id'] ?? null,
        );
    }

    /**
     * @throws AiClientException
     */
    public function validateGuest(array $payload): void
    {
        $this->requireExactKeys($payload, self::GUEST_TOP_KEYS);

        $this->requireStringValue($payload['schema_version'] ?? null, '1.0');
        $this->requireUuid($payload['request_id'] ?? null);
        $this->requireGuestSessionReference($payload['guest_session_reference'] ?? null);
        $this->requireStringValue($payload['language'] ?? null, 'auto');
        $this->requireMessages($payload['messages'] ?? null);
    }

    private function requireExactKeys(array $array, array $expected): void
    {
        $actual = array_keys($array);
        sort($actual);

        if ($actual !== $expected) {
            $this->fail();
        }
    }

    private function requireStringValue(mixed $value, string $expected): void
    {
        if ($value !== $expected) {
            $this->fail();
        }
    }

    private function requireUuid(mixed $value): void
    {
        if (! is_string($value) || ! preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value,
        )) {
            $this->fail();
        }
    }

    private function requireUserReference(mixed $value, mixed $studentId): void
    {
        if (
            ! is_string($value)
            || ! is_string($studentId)
            || $studentId === ''
            || $value !== 'student:' . $studentId
        ) {
            $this->fail();
        }
    }

    private function requireGuestSessionReference(mixed $value): void
    {
        if (! is_string($value) || ! preg_match('/^[a-f0-9]{64}$/', $value)) {
            $this->fail();
        }
    }

    private function requireMessages(mixed $value): void
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            $this->fail();
        }

        foreach ($value as $msg) {
            if (! is_array($msg)) {
                $this->fail();
            }

            $this->requireExactKeys($msg, self::MESSAGE_KEYS);

            if (! in_array($msg['role'] ?? '', ['user', 'assistant'], true)) {
                $this->fail();
            }

            if (! is_string($msg['content'] ?? null) || trim($msg['content']) === '') {
                $this->fail();
            }
        }
    }

    private function requireStudentContext(mixed $value): void
    {
        if (! is_array($value)) {
            $this->fail();
        }

        $this->requireExactKeys($value, self::STUDENT_CONTEXT_KEYS);

        if (! is_string($value['student_id'])) {
            $this->fail();
        }
        if (! is_string($value['full_name'])) {
            $this->fail();
        }
        if (! is_string($value['email']) || filter_var($value['email'], FILTER_VALIDATE_EMAIL) === false) {
            $this->fail();
        }
        if (! is_int($value['faculty_id'])) {
            $this->fail();
        }
        if ($value['faculty_name'] !== null && ! is_string($value['faculty_name'])) {
            $this->fail();
        }
        if (! is_float($value['gpa']) && ! is_int($value['gpa'])) {
            $this->fail();
        }
        if (! is_int($value['credits_completed'])) {
            $this->fail();
        }
        if (! is_int($value['credits_required'])) {
            $this->fail();
        }
    }

    /** @throws AiClientException */
    private function fail(): never
    {
        throw new AiClientException(self::ERR_CODE, self::ERR_MSG);
    }
}
