<?php

namespace Tests\Feature\Api\Student;

use App\Models\ChatAiRequest;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Student Chat API regression tests.
 *
 * Routes (all under /api/v1/student/chats, auth:sanctum + ensure.student):
 *   POST   ''                                  → store (new conversation + first message)
 *   GET    ''                                  → index (list own visible conversations)
 *   GET    '{chatUuid}'                        → show (conversation + messages)
 *   PATCH  '{chatUuid}'                        → update (rename)
 *   DELETE '{chatUuid}'                        → destroy (student-hide)
 *   POST   '{chatUuid}/messages'               → sendMessage
 *   GET    '{chatUuid}/messages/{uuid}/status' → messageStatus
 *   POST   '{chatUuid}/messages/{uuid}/retry'  → retryMessage
 *
 * Key behaviors:
 *   - client_message_id (UUID) is required for store + sendMessage; provides idempotency.
 *   - Message max length is enforced by config('chat.max_message_length', 3000).
 *   - destroy() is a soft-hide (sets deleted_by_student_at), not a hard delete.
 *   - retryMessage() is only allowed when the assistant message status = failed.
 *
 * Queue strategy:
 *   All tests use Queue::fake() to prevent ProcessStudentAiChat from executing.
 *   Job behavior is tested separately in ProcessStudentAiChatTest.
 */
class StudentChatApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Prevent AI jobs from running during API tests; tested separately.
        Queue::fake();
    }

    // =========================================================================
    // A) Authentication guard
    // =========================================================================

    public function test_unauthenticated_cannot_access_chat_list(): void
    {
        $this->getJson('/api/v1/student/chats')->assertUnauthorized();
    }

    public function test_unauthenticated_cannot_store_chat(): void
    {
        $this->postJson('/api/v1/student/chats', [
            'message' => 'Hello',
            'client_message_id' => (string) Str::uuid(),
        ])->assertUnauthorized();
    }

    // =========================================================================
    // B) store — create new conversation
    // =========================================================================

    public function test_store_creates_conversation_with_correct_structure(): void
    {
        $student = Student::factory()->create();
        Sanctum::actingAs($student, ['*']);

        $clientId = (string) Str::uuid();

        $this->postJson('/api/v1/student/chats', [
            'message' => 'What is the capital of Egypt?',
            'client_message_id' => $clientId,
        ])
            ->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'chat' => ['uuid', 'title', 'last_message_at'],
                    'user_message' => ['uuid', 'role', 'content', 'status', 'sequence_number'],
                    'assistant_message' => ['uuid', 'role', 'content', 'status', 'sequence_number'],
                    'ai_request' => ['uuid', 'status', 'attempt_number'],
                ],
            ]);

        $this->assertDatabaseHas('chat_conversations', ['student_id' => $student->id]);
        $this->assertDatabaseHas('chat_messages', [
            'role' => ChatMessage::ROLE_USER,
            'client_message_id' => $clientId,
            'status' => ChatMessage::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('chat_messages', [
            'role' => ChatMessage::ROLE_ASSISTANT,
            'status' => ChatMessage::STATUS_PENDING,
        ]);
        $this->assertDatabaseHas('chat_ai_requests', [
            'status' => ChatAiRequest::STATUS_QUEUED,
        ]);
    }

    public function test_store_requires_message(): void
    {
        Sanctum::actingAs(Student::factory()->create(), ['*']);

        $this->postJson('/api/v1/student/chats', [
            'message' => '',
            'client_message_id' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['message']);
    }

    public function test_store_requires_client_message_id(): void
    {
        Sanctum::actingAs(Student::factory()->create(), ['*']);

        $this->postJson('/api/v1/student/chats', [
            'message' => 'Hello',
        ])->assertUnprocessable()->assertJsonValidationErrors(['client_message_id']);
    }

    public function test_store_requires_valid_uuid_for_client_message_id(): void
    {
        Sanctum::actingAs(Student::factory()->create(), ['*']);

        $this->postJson('/api/v1/student/chats', [
            'message' => 'Hello',
            'client_message_id' => 'not-a-uuid',
        ])->assertUnprocessable()->assertJsonValidationErrors(['client_message_id']);
    }

    public function test_store_rejects_message_over_max_length(): void
    {
        Sanctum::actingAs(Student::factory()->create(), ['*']);

        $this->postJson('/api/v1/student/chats', [
            'message' => str_repeat('x', 3001),
            'client_message_id' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['message']);
    }

    public function test_store_is_idempotent_for_same_client_message_id(): void
    {
        $student = Student::factory()->create();
        Sanctum::actingAs($student, ['*']);

        $clientId = (string) Str::uuid();
        $payload = ['message' => 'Hello', 'client_message_id' => $clientId];

        $first = $this->postJson('/api/v1/student/chats', $payload)->assertStatus(202);
        $second = $this->postJson('/api/v1/student/chats', $payload)->assertStatus(202);

        // Same AI request UUID returned both times.
        $this->assertSame(
            $first->json('data.ai_request.uuid'),
            $second->json('data.ai_request.uuid'),
        );

        // Only one conversation created.
        $this->assertDatabaseCount('chat_conversations', 1);
        $this->assertDatabaseCount('chat_messages', 2); // user + assistant
        $this->assertDatabaseCount('chat_ai_requests', 1);
    }

    public function test_store_collision_returns_409_when_client_id_belongs_to_another_student(): void
    {
        // client_message_id global uniqueness — another student already used it.
        $studentA = Student::factory()->create();
        $studentB = Student::factory()->create();

        $clientId = (string) Str::uuid();

        Sanctum::actingAs($studentA, ['*']);
        $this->postJson('/api/v1/student/chats', [
            'message' => 'First',
            'client_message_id' => $clientId,
        ])->assertStatus(202);

        Sanctum::actingAs($studentB, ['*']);
        $this->postJson('/api/v1/student/chats', [
            'message' => 'Second',
            'client_message_id' => $clientId,
        ])->assertStatus(409);
    }

    // =========================================================================
    // C) index — list conversations
    // =========================================================================

    public function test_index_returns_only_own_visible_chats(): void
    {
        $studentA = Student::factory()->create();
        $studentB = Student::factory()->create();

        ChatConversation::factory()->create(['student_id' => $studentA->id]);
        ChatConversation::factory()->create(['student_id' => $studentB->id]);

        Sanctum::actingAs($studentA, ['*']);

        $data = $this->getJson('/api/v1/student/chats')
            ->assertOk()
            ->json('data.conversations');

        $this->assertCount(1, $data);
    }

    public function test_index_excludes_student_hidden_chats(): void
    {
        $student = Student::factory()->create();
        ChatConversation::factory()->create(['student_id' => $student->id]);
        ChatConversation::factory()->create([
            'student_id' => $student->id,
            'deleted_by_student_at' => now(),
        ]);

        Sanctum::actingAs($student, ['*']);

        $data = $this->getJson('/api/v1/student/chats')
            ->assertOk()
            ->json('data.conversations');

        $this->assertCount(1, $data);
    }

    // =========================================================================
    // D) show — single conversation
    // =========================================================================

    public function test_show_returns_own_chat_with_messages(): void
    {
        $student = Student::factory()->create();
        $conversation = ChatConversation::factory()->create(['student_id' => $student->id]);
        ChatMessage::factory()->create([
            'chat_conversation_id' => $conversation->id,
            'role' => ChatMessage::ROLE_USER,
            'sequence_number' => 1,
        ]);

        Sanctum::actingAs($student, ['*']);

        $this->getJson("/api/v1/student/chats/{$conversation->uuid}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['conversation', 'messages']]);
    }

    public function test_show_returns_404_for_another_students_chat(): void
    {
        $studentA = Student::factory()->create();
        $studentB = Student::factory()->create();
        $conversation = ChatConversation::factory()->create(['student_id' => $studentA->id]);

        Sanctum::actingAs($studentB, ['*']);

        $this->getJson("/api/v1/student/chats/{$conversation->uuid}")
            ->assertNotFound();
    }

    public function test_show_returns_404_for_own_hidden_chat(): void
    {
        $student = Student::factory()->create();
        $conversation = ChatConversation::factory()->create([
            'student_id' => $student->id,
            'deleted_by_student_at' => now(),
        ]);

        Sanctum::actingAs($student, ['*']);

        $this->getJson("/api/v1/student/chats/{$conversation->uuid}")
            ->assertNotFound();
    }

    // =========================================================================
    // E) update — rename
    // =========================================================================

    public function test_update_renames_own_chat(): void
    {
        $student = Student::factory()->create();
        $conversation = ChatConversation::factory()->create(['student_id' => $student->id]);

        Sanctum::actingAs($student, ['*']);

        $this->patchJson("/api/v1/student/chats/{$conversation->uuid}", ['title' => 'New Title'])
            ->assertOk()
            ->assertJsonPath('data.title', 'New Title');

        $this->assertSame('New Title', $conversation->fresh()->title);
    }

    public function test_update_requires_title(): void
    {
        $student = Student::factory()->create();
        $conversation = ChatConversation::factory()->create(['student_id' => $student->id]);

        Sanctum::actingAs($student, ['*']);

        $this->patchJson("/api/v1/student/chats/{$conversation->uuid}", ['title' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title']);
    }

    public function test_update_returns_404_for_another_students_chat(): void
    {
        $studentA = Student::factory()->create();
        $studentB = Student::factory()->create();
        $conversation = ChatConversation::factory()->create(['student_id' => $studentA->id]);

        Sanctum::actingAs($studentB, ['*']);

        $this->patchJson("/api/v1/student/chats/{$conversation->uuid}", ['title' => 'X'])
            ->assertNotFound();
    }

    // =========================================================================
    // F) destroy — student-hide
    // =========================================================================

    public function test_destroy_soft_hides_chat(): void
    {
        $student = Student::factory()->create();
        $conversation = ChatConversation::factory()->create(['student_id' => $student->id]);

        Sanctum::actingAs($student, ['*']);

        $this->deleteJson("/api/v1/student/chats/{$conversation->uuid}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNotNull($conversation->fresh()->deleted_by_student_at);
        // Row still exists in DB — not a hard delete.
        $this->assertDatabaseHas('chat_conversations', ['id' => $conversation->id]);
    }

    public function test_destroy_returns_404_for_another_students_chat(): void
    {
        $studentA = Student::factory()->create();
        $studentB = Student::factory()->create();
        $conversation = ChatConversation::factory()->create(['student_id' => $studentA->id]);

        Sanctum::actingAs($studentB, ['*']);

        $this->deleteJson("/api/v1/student/chats/{$conversation->uuid}")
            ->assertNotFound();
    }

    // =========================================================================
    // G) sendMessage — follow-up message in existing conversation
    // =========================================================================

    public function test_send_message_to_completed_conversation(): void
    {
        $student = Student::factory()->create();
        $conversation = $this->makeCompletedConversation($student);

        Sanctum::actingAs($student, ['*']);

        $this->postJson("/api/v1/student/chats/{$conversation->uuid}/messages", [
            'message' => 'Follow-up question?',
            'client_message_id' => (string) Str::uuid(),
        ])
            ->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.ai_request.status', ChatAiRequest::STATUS_QUEUED);
    }

    public function test_send_message_is_idempotent_for_same_client_message_id(): void
    {
        $student = Student::factory()->create();
        $conversation = $this->makeCompletedConversation($student);

        Sanctum::actingAs($student, ['*']);

        $clientId = (string) Str::uuid();
        $payload = ['message' => 'Same message', 'client_message_id' => $clientId];
        $url = "/api/v1/student/chats/{$conversation->uuid}/messages";

        $first = $this->postJson($url, $payload)->assertStatus(202);
        $second = $this->postJson($url, $payload)->assertStatus(202);

        $this->assertSame(
            $first->json('data.ai_request.uuid'),
            $second->json('data.ai_request.uuid'),
        );
    }

    public function test_send_message_returns_409_when_previous_response_is_pending(): void
    {
        // A pending assistant message blocks new messages for the same conversation.
        $student = Student::factory()->create();
        $conversation = ChatConversation::factory()->create(['student_id' => $student->id]);
        ChatMessage::factory()->create([
            'chat_conversation_id' => $conversation->id,
            'role' => ChatMessage::ROLE_USER,
            'status' => ChatMessage::STATUS_COMPLETED,
            'sequence_number' => 1,
        ]);
        ChatMessage::factory()->pendingAssistant()->create([
            'chat_conversation_id' => $conversation->id,
            'sequence_number' => 2,
        ]);

        Sanctum::actingAs($student, ['*']);

        $this->postJson("/api/v1/student/chats/{$conversation->uuid}/messages", [
            'message' => 'Another question',
            'client_message_id' => (string) Str::uuid(),
        ])->assertStatus(409);
    }

    // =========================================================================
    // H) messageStatus — poll AI result
    // =========================================================================

    public function test_message_status_returns_queued_state(): void
    {
        $student = Student::factory()->create();
        [$conversation, $userMsg, $assistantMsg] = $this->makeQueuedCycle($student);

        Sanctum::actingAs($student, ['*']);

        $this->getJson("/api/v1/student/chats/{$conversation->uuid}/messages/{$assistantMsg->uuid}/status")
            ->assertOk()
            ->assertJsonPath('data.assistant_message.status', ChatMessage::STATUS_PENDING)
            ->assertJsonPath('data.ai_request.status', ChatAiRequest::STATUS_QUEUED);
    }

    public function test_message_status_returns_completed_state(): void
    {
        $student = Student::factory()->create();
        [$conversation, $userMsg, $assistantMsg] = $this->makeCompletedCycle($student);

        Sanctum::actingAs($student, ['*']);

        $this->getJson("/api/v1/student/chats/{$conversation->uuid}/messages/{$assistantMsg->uuid}/status")
            ->assertOk()
            ->assertJsonPath('data.assistant_message.status', ChatMessage::STATUS_COMPLETED)
            ->assertJsonPath('data.ai_request.status', ChatAiRequest::STATUS_COMPLETED);
    }

    public function test_student_cannot_access_another_students_message_status(): void
    {
        $studentA = Student::factory()->create();
        $studentB = Student::factory()->create();
        [$conversation, , $assistantMsg] = $this->makeQueuedCycle($studentA);

        Sanctum::actingAs($studentB, ['*']);

        $this->getJson("/api/v1/student/chats/{$conversation->uuid}/messages/{$assistantMsg->uuid}/status")
            ->assertNotFound();
    }

    // =========================================================================
    // I) retryMessage
    // =========================================================================

    public function test_retry_works_for_failed_assistant_message(): void
    {
        $student = Student::factory()->create();
        [$conversation, , $assistantMsg] = $this->makeFailedCycle($student);

        Sanctum::actingAs($student, ['*']);

        $this->postJson("/api/v1/student/chats/{$conversation->uuid}/messages/{$assistantMsg->uuid}/retry")
            ->assertStatus(202)
            ->assertJsonPath('data.ai_request.attempt_number', 2);

        // A new AI request with attempt_number=2 was created.
        $this->assertDatabaseHas('chat_ai_requests', [
            'assistant_message_id' => $assistantMsg->id,
            'attempt_number' => 2,
            'status' => ChatAiRequest::STATUS_QUEUED,
        ]);
    }

    public function test_retry_is_blocked_for_pending_assistant_message(): void
    {
        $student = Student::factory()->create();
        [$conversation, , $assistantMsg] = $this->makeQueuedCycle($student);

        Sanctum::actingAs($student, ['*']);

        // Assistant message is 'pending' (not 'failed') → 422.
        $this->postJson("/api/v1/student/chats/{$conversation->uuid}/messages/{$assistantMsg->uuid}/retry")
            ->assertUnprocessable();
    }

    public function test_student_cannot_retry_another_students_message(): void
    {
        $studentA = Student::factory()->create();
        $studentB = Student::factory()->create();
        [$conversation, , $assistantMsg] = $this->makeFailedCycle($studentA);

        Sanctum::actingAs($studentB, ['*']);

        $this->postJson("/api/v1/student/chats/{$conversation->uuid}/messages/{$assistantMsg->uuid}/retry")
            ->assertNotFound();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** A conversation whose last AI exchange is complete (no pending message). */
    private function makeCompletedConversation(Student $student): ChatConversation
    {
        $conversation = ChatConversation::factory()->create(['student_id' => $student->id]);
        ChatMessage::factory()->create([
            'chat_conversation_id' => $conversation->id,
            'role' => ChatMessage::ROLE_USER,
            'status' => ChatMessage::STATUS_COMPLETED,
            'sequence_number' => 1,
        ]);
        ChatMessage::factory()->create([
            'chat_conversation_id' => $conversation->id,
            'role' => ChatMessage::ROLE_ASSISTANT,
            'content' => 'AI response',
            'status' => ChatMessage::STATUS_COMPLETED,
            'sequence_number' => 2,
            'client_message_id' => null,
        ]);

        return $conversation;
    }

    /** [conversation, userMsg, assistantMsg] — AI request is queued, assistant is pending. */
    private function makeQueuedCycle(Student $student): array
    {
        $conversation = ChatConversation::factory()->create(['student_id' => $student->id]);
        $userMsg = ChatMessage::factory()->create([
            'chat_conversation_id' => $conversation->id,
            'role' => ChatMessage::ROLE_USER,
            'status' => ChatMessage::STATUS_COMPLETED,
            'sequence_number' => 1,
        ]);
        $assistantMsg = ChatMessage::factory()->pendingAssistant()->create([
            'chat_conversation_id' => $conversation->id,
            'sequence_number' => 2,
        ]);
        ChatAiRequest::factory()->queued()->forCycle($conversation, $userMsg, $assistantMsg)->create();

        return [$conversation, $userMsg, $assistantMsg];
    }

    /** [conversation, userMsg, assistantMsg] — AI request and assistant are completed. */
    private function makeCompletedCycle(Student $student): array
    {
        $conversation = ChatConversation::factory()->withSession()->create(['student_id' => $student->id]);
        $userMsg = ChatMessage::factory()->create([
            'chat_conversation_id' => $conversation->id,
            'role' => ChatMessage::ROLE_USER,
            'status' => ChatMessage::STATUS_COMPLETED,
            'sequence_number' => 1,
        ]);
        $assistantMsg = ChatMessage::factory()->create([
            'chat_conversation_id' => $conversation->id,
            'role' => ChatMessage::ROLE_ASSISTANT,
            'content' => 'AI answer.',
            'status' => ChatMessage::STATUS_COMPLETED,
            'sequence_number' => 2,
            'client_message_id' => null,
        ]);
        ChatAiRequest::factory()->completed()->forCycle($conversation, $userMsg, $assistantMsg)->create();

        return [$conversation, $userMsg, $assistantMsg];
    }

    /** [conversation, userMsg, assistantMsg] — AI request failed, assistant is failed. */
    private function makeFailedCycle(Student $student): array
    {
        $conversation = ChatConversation::factory()->create(['student_id' => $student->id]);
        $userMsg = ChatMessage::factory()->create([
            'chat_conversation_id' => $conversation->id,
            'role' => ChatMessage::ROLE_USER,
            'status' => ChatMessage::STATUS_COMPLETED,
            'sequence_number' => 1,
        ]);
        $assistantMsg = ChatMessage::factory()->failedAssistant()->create([
            'chat_conversation_id' => $conversation->id,
            'sequence_number' => 2,
        ]);
        ChatAiRequest::factory()->failed()->forCycle($conversation, $userMsg, $assistantMsg)->create();

        return [$conversation, $userMsg, $assistantMsg];
    }
}
