<?php

namespace Tests\Feature\Student;

use App\Jobs\ProcessStudentAiChat;
use App\Models\ChatAiRequest;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Faculty;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function makeStudent(): Student
    {
        $faculty = Faculty::factory()->create();

        return Student::factory()->create(['faculty_id' => $faculty->id]);
    }

    private function chatPayload(string $message = 'Hello'): array
    {
        return [
            'message'           => $message,
            'client_message_id' => (string) \Illuminate\Support\Str::uuid(),
        ];
    }

    // ── store() ───────────────────────────────────────────────────────────────

    public function test_store_dispatches_job_to_redis_ai_chat_queue(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);

        $this->postJson('/api/v1/student/chats', $this->chatPayload())
            ->assertStatus(202);

        Queue::assertPushed(
            ProcessStudentAiChat::class,
            fn (ProcessStudentAiChat $job) =>
                $job->connection === 'redis'
                && $job->queue === config('chat.ai_queue'),
        );
    }

    public function test_store_dispatches_job_with_scalar_ai_request_id(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);

        $this->postJson('/api/v1/student/chats', $this->chatPayload())
            ->assertStatus(202);

        Queue::assertPushed(
            ProcessStudentAiChat::class,
            fn (ProcessStudentAiChat $job) => is_int($job->aiRequestId),
        );
    }

    public function test_store_dispatches_exactly_one_job(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);

        $this->postJson('/api/v1/student/chats', $this->chatPayload());

        Queue::assertPushed(ProcessStudentAiChat::class, 1);
    }

    // ── sendMessage() ─────────────────────────────────────────────────────────

    public function test_send_message_dispatches_job_after_commit(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);

        // Create an initial chat with a completed assistant turn
        $conversation = ChatConversation::factory()->create(['student_id' => $student->id]);
        ChatMessage::factory()->create([
            'chat_conversation_id' => $conversation->id,
            'role'                 => ChatMessage::ROLE_USER,
            'status'               => ChatMessage::STATUS_COMPLETED,
            'sequence_number'      => 1,
        ]);
        ChatMessage::factory()->create([
            'chat_conversation_id' => $conversation->id,
            'role'                 => ChatMessage::ROLE_ASSISTANT,
            'status'               => ChatMessage::STATUS_COMPLETED,
            'content'              => 'First reply',
            'sequence_number'      => 2,
        ]);

        $this->postJson("/api/v1/student/chats/{$conversation->uuid}/messages", $this->chatPayload('Follow-up'))
            ->assertStatus(202);

        Queue::assertPushed(
            ProcessStudentAiChat::class,
            fn (ProcessStudentAiChat $job) =>
                $job->connection === 'redis'
                && $job->queue === config('chat.ai_queue'),
        );
    }

    // ── retryMessage() ────────────────────────────────────────────────────────

    public function test_retry_dispatches_job_after_commit(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);

        $conversation = ChatConversation::factory()->create(['student_id' => $student->id]);
        $userMsg      = ChatMessage::factory()->create([
            'chat_conversation_id' => $conversation->id,
            'role'                 => ChatMessage::ROLE_USER,
            'status'               => ChatMessage::STATUS_COMPLETED,
            'sequence_number'      => 1,
        ]);
        $assistantMsg = ChatMessage::factory()->create([
            'chat_conversation_id' => $conversation->id,
            'role'                 => ChatMessage::ROLE_ASSISTANT,
            'status'               => ChatMessage::STATUS_FAILED,
            'sequence_number'      => 2,
        ]);
        ChatAiRequest::factory()->create([
            'chat_conversation_id' => $conversation->id,
            'user_message_id'      => $userMsg->id,
            'assistant_message_id' => $assistantMsg->id,
            'status'               => ChatAiRequest::STATUS_FAILED,
            'attempt_number'       => 1,
        ]);

        $this->postJson("/api/v1/student/chats/{$conversation->uuid}/messages/{$assistantMsg->uuid}/retry")
            ->assertStatus(202);

        Queue::assertPushed(
            ProcessStudentAiChat::class,
            fn (ProcessStudentAiChat $job) =>
                $job->connection === 'redis'
                && $job->queue === config('chat.ai_queue'),
        );
    }

    // ── idempotency — no double dispatch ──────────────────────────────────────

    public function test_no_dispatch_on_idempotent_202_replay(): void
    {
        $student         = $this->makeStudent();
        $clientMessageId = (string) \Illuminate\Support\Str::uuid();
        Sanctum::actingAs($student);

        // First request — creates chat and dispatches job
        $this->postJson('/api/v1/student/chats', [
            'message'           => 'Hello',
            'client_message_id' => $clientMessageId,
        ])->assertStatus(202);

        Queue::assertPushed(ProcessStudentAiChat::class, 1);

        // Replay with the same client_message_id — should return 202 but NOT dispatch again
        $this->postJson('/api/v1/student/chats', [
            'message'           => 'Hello',
            'client_message_id' => $clientMessageId,
        ])->assertStatus(202);

        Queue::assertPushed(ProcessStudentAiChat::class, 1); // still 1, not 2
    }

    public function test_no_dispatch_on_409_collision(): void
    {
        $studentA        = $this->makeStudent();
        $studentB        = $this->makeStudent();
        $clientMessageId = (string) \Illuminate\Support\Str::uuid();

        Sanctum::actingAs($studentA);
        $this->postJson('/api/v1/student/chats', [
            'message'           => 'Hello',
            'client_message_id' => $clientMessageId,
        ])->assertStatus(202);

        Queue::assertPushed(ProcessStudentAiChat::class, 1);

        // Different student uses same client_message_id — should 409, not dispatch
        Sanctum::actingAs($studentB);
        $this->postJson('/api/v1/student/chats', [
            'message'           => 'Hello',
            'client_message_id' => $clientMessageId,
        ])->assertStatus(409);

        Queue::assertPushed(ProcessStudentAiChat::class, 1); // still 1
    }
}
