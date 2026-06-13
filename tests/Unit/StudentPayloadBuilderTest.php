<?php

namespace Tests\Unit;

use App\Models\ChatAiRequest;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Faculty;
use App\Models\Student;
use App\Services\Ai\StudentPayloadBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentPayloadBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function makeFullCycle(array $studentOverrides = []): array
    {
        $faculty  = Faculty::factory()->create(['name' => 'Engineering']);
        $student  = Student::factory()->create(array_merge([
            'faculty_id'        => $faculty->id,
            'student_id'        => 'STU-001',
            'full_name'         => 'Ahmed Ali',
            'email'             => 'ahmed@example.com',
            'gpa'               => '3.75',
            'credits_completed' => 90,
            'credits_required'  => 130,
        ], $studentOverrides));

        $conversation = ChatConversation::factory()->create(['student_id' => $student->id]);

        $userMsg = ChatMessage::factory()->create([
            'chat_conversation_id' => $conversation->id,
            'role'                 => ChatMessage::ROLE_USER,
            'content'              => 'Hello AI',
            'status'               => ChatMessage::STATUS_COMPLETED,
            'sequence_number'      => 1,
        ]);

        $assistantMsg = ChatMessage::factory()->create([
            'chat_conversation_id' => $conversation->id,
            'role'                 => ChatMessage::ROLE_ASSISTANT,
            'content'              => null,
            'status'               => ChatMessage::STATUS_PENDING,
            'sequence_number'      => 2,
        ]);

        $aiRequest = ChatAiRequest::factory()->create([
            'chat_conversation_id' => $conversation->id,
            'user_message_id'      => $userMsg->id,
            'assistant_message_id' => $assistantMsg->id,
            'status'               => ChatAiRequest::STATUS_QUEUED,
            'attempt_number'       => 1,
        ]);

        return compact('student', 'faculty', 'conversation', 'userMsg', 'assistantMsg', 'aiRequest');
    }

    public function test_request_id_equals_ai_request_uuid(): void
    {
        ['aiRequest' => $aiRequest] = $this->makeFullCycle();

        $payload = (new StudentPayloadBuilder($aiRequest))->build();

        $this->assertSame($aiRequest->uuid, $payload['request_id']);
    }

    public function test_conversation_id_equals_conversation_uuid(): void
    {
        ['aiRequest' => $aiRequest, 'conversation' => $conversation] = $this->makeFullCycle();

        $payload = (new StudentPayloadBuilder($aiRequest))->build();

        $this->assertSame($conversation->uuid, $payload['conversation_id']);
    }

    public function test_user_reference_format(): void
    {
        ['aiRequest' => $aiRequest, 'student' => $student] = $this->makeFullCycle();

        $payload = (new StudentPayloadBuilder($aiRequest))->build();

        $this->assertSame('student:' . $student->student_id, $payload['user_reference']);
    }

    public function test_language_is_auto(): void
    {
        ['aiRequest' => $aiRequest] = $this->makeFullCycle();

        $payload = (new StudentPayloadBuilder($aiRequest))->build();

        $this->assertSame('auto', $payload['language']);
    }

    public function test_messages_include_completed_user_messages(): void
    {
        ['aiRequest' => $aiRequest, 'userMsg' => $userMsg] = $this->makeFullCycle();

        $payload = (new StudentPayloadBuilder($aiRequest))->build();

        $this->assertContains(
            ['role' => ChatMessage::ROLE_USER, 'content' => $userMsg->content],
            $payload['messages'],
        );
    }

    public function test_messages_exclude_pending_assistant_placeholder(): void
    {
        ['aiRequest' => $aiRequest] = $this->makeFullCycle();

        $payload = (new StudentPayloadBuilder($aiRequest))->build();

        foreach ($payload['messages'] as $msg) {
            $this->assertNotEquals(ChatMessage::STATUS_PENDING, $msg['content'] ?? '');
        }

        // The pending assistant placeholder should not appear (status != completed)
        $this->assertCount(1, $payload['messages']); // only the user message is completed
    }

    public function test_completed_assistant_message_is_included(): void
    {
        ['aiRequest' => $aiRequest, 'assistantMsg' => $assistantMsg] = $this->makeFullCycle();

        // Mark assistant as completed
        $assistantMsg->update(['status' => ChatMessage::STATUS_COMPLETED, 'content' => 'AI answer']);

        $payload = (new StudentPayloadBuilder($aiRequest))->build();

        $roles = array_column($payload['messages'], 'role');
        $this->assertContains(ChatMessage::ROLE_ASSISTANT, $roles);
    }

    public function test_failed_messages_excluded(): void
    {
        ['aiRequest' => $aiRequest, 'conversation' => $conversation] = $this->makeFullCycle();

        ChatMessage::factory()->create([
            'chat_conversation_id' => $conversation->id,
            'role'                 => ChatMessage::ROLE_ASSISTANT,
            'status'               => ChatMessage::STATUS_FAILED,
            'sequence_number'      => 3,
        ]);

        $payload = (new StudentPayloadBuilder($aiRequest))->build();

        foreach ($payload['messages'] as $msg) {
            $this->assertNotEquals(ChatMessage::STATUS_FAILED, $msg);
        }
    }

    public function test_messages_ordered_by_sequence_number_asc(): void
    {
        ['aiRequest' => $aiRequest, 'conversation' => $conversation, 'assistantMsg' => $assistantMsg] = $this->makeFullCycle();

        $assistantMsg->update(['status' => ChatMessage::STATUS_COMPLETED, 'content' => 'AI reply']);

        $payload = (new StudentPayloadBuilder($aiRequest))->build();

        $this->assertSame(ChatMessage::ROLE_USER,      $payload['messages'][0]['role']);
        $this->assertSame(ChatMessage::ROLE_ASSISTANT, $payload['messages'][1]['role']);
    }

    public function test_student_context_has_exactly_eight_keys(): void
    {
        ['aiRequest' => $aiRequest] = $this->makeFullCycle();

        $payload = (new StudentPayloadBuilder($aiRequest))->build();

        $this->assertCount(8, $payload['student_context']);
    }

    public function test_student_context_contains_correct_field_values(): void
    {
        ['aiRequest' => $aiRequest, 'student' => $student, 'faculty' => $faculty] = $this->makeFullCycle();

        $payload = (new StudentPayloadBuilder($aiRequest))->build();
        $ctx     = $payload['student_context'];

        $this->assertSame($student->student_id,        $ctx['student_id']);
        $this->assertSame($student->full_name,          $ctx['full_name']);
        $this->assertSame($student->email,              $ctx['email']);
        $this->assertSame($student->faculty_id,         $ctx['faculty_id']);
        $this->assertSame($faculty->name,               $ctx['faculty_name']);
        $this->assertIsFloat($ctx['gpa']);
        $this->assertSame($student->credits_completed,  $ctx['credits_completed']);
        $this->assertSame($student->credits_required,   $ctx['credits_required']);
    }

    public function test_student_context_excludes_password(): void
    {
        ['aiRequest' => $aiRequest] = $this->makeFullCycle();

        $payload = (new StudentPayloadBuilder($aiRequest))->build();

        $this->assertArrayNotHasKey('password', $payload['student_context']);
    }

    public function test_student_context_excludes_token_related_fields(): void
    {
        ['aiRequest' => $aiRequest] = $this->makeFullCycle();

        $payload = (new StudentPayloadBuilder($aiRequest))->build();

        $this->assertArrayNotHasKey('remember_token', $payload['student_context']);
        $this->assertArrayNotHasKey('tokens',         $payload['student_context']);
    }

    public function test_schema_version_is_1_0(): void
    {
        ['aiRequest' => $aiRequest] = $this->makeFullCycle();

        $payload = (new StudentPayloadBuilder($aiRequest))->build();

        $this->assertSame('1.0', $payload['schema_version']);
    }

    public function test_top_level_payload_keys_are_correct(): void
    {
        ['aiRequest' => $aiRequest] = $this->makeFullCycle();

        $payload = (new StudentPayloadBuilder($aiRequest))->build();

        $this->assertArrayHasKey('schema_version',  $payload);
        $this->assertArrayHasKey('request_id',      $payload);
        $this->assertArrayHasKey('conversation_id', $payload);
        $this->assertArrayHasKey('user_reference',  $payload);
        $this->assertArrayHasKey('language',        $payload);
        $this->assertArrayHasKey('messages',        $payload);
        $this->assertArrayHasKey('student_context', $payload);
    }

    public function test_system_role_messages_excluded_from_history(): void
    {
        ['aiRequest' => $aiRequest, 'conversation' => $conversation] = $this->makeFullCycle();

        // Completed system message — must not appear in payload
        ChatMessage::factory()->create([
            'chat_conversation_id' => $conversation->id,
            'role'                 => ChatMessage::ROLE_SYSTEM,
            'content'              => 'You are a helpful assistant.',
            'status'               => ChatMessage::STATUS_COMPLETED,
            'sequence_number'      => 3,
        ]);

        $payload = (new StudentPayloadBuilder($aiRequest))->build();

        foreach ($payload['messages'] as $msg) {
            $this->assertNotEquals(ChatMessage::ROLE_SYSTEM, $msg['role']);
        }
    }

    public function test_student_context_does_not_include_vehicle_requests(): void
    {
        ['aiRequest' => $aiRequest] = $this->makeFullCycle();

        $payload = (new StudentPayloadBuilder($aiRequest))->build();

        $this->assertArrayNotHasKey('vehicle_requests', $payload['student_context']);
    }

    public function test_student_context_does_not_include_admin(): void
    {
        ['aiRequest' => $aiRequest] = $this->makeFullCycle();

        $payload = (new StudentPayloadBuilder($aiRequest))->build();

        $this->assertArrayNotHasKey('admin', $payload['student_context']);
    }

    public function test_student_context_does_not_include_admins(): void
    {
        ['aiRequest' => $aiRequest] = $this->makeFullCycle();

        $payload = (new StudentPayloadBuilder($aiRequest))->build();

        $this->assertArrayNotHasKey('admins', $payload['student_context']);
    }
}
