<?php

namespace Tests\Feature\Student;

use App\Contracts\StudentAiChatClientContract;
use App\Exceptions\AiClientException;
use App\Jobs\ProcessStudentAiChat;
use App\Models\ChatAiRequest;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Faculty;
use App\Models\Student;
use App\Services\Ai\FakeStudentAiChatClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Prevent afterCommit() from attempting a real Redis connection in tests.
        // Dispatch behaviour is asserted separately in ChatDispatchTest.
        Queue::fake();
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeStudent(array $overrides = []): Student
    {
        $faculty = Faculty::factory()->create();

        return Student::factory()->create(array_merge(['faculty_id' => $faculty->id], $overrides));
    }

    private function chatPayload(string $message = 'Hello AI', ?string $uuid = null): array
    {
        return [
            'message'           => $message,
            'client_message_id' => $uuid ?? (string) \Illuminate\Support\Str::uuid(),
        ];
    }

    private function makeFakeClient(string $mode = 'success'): FakeStudentAiChatClient
    {
        $fake = new FakeStudentAiChatClient();

        if ($mode === 'success') {
            $fake->shouldSucceed('AI response content');
        } elseif ($mode === 'failure') {
            $fake->shouldFail('AI_ERROR', 'Simulated failure');
        } elseif ($mode === 'timeout') {
            $fake->shouldTimeout();
        }

        $this->app->instance(StudentAiChatClientContract::class, $fake);

        return $fake;
    }

    private function makeConversationWithCompletedTurn(Student $student): array
    {
        $conversation = ChatConversation::factory()->create([
            'student_id'      => $student->id,
            'last_message_at' => now(),
        ]);
        $userMsg = ChatMessage::factory()->create([
            'chat_conversation_id' => $conversation->id,
            'role'                 => ChatMessage::ROLE_USER,
            'status'               => ChatMessage::STATUS_COMPLETED,
            'content'              => 'First question',
            'sequence_number'      => 1,
        ]);
        $assistantMsg = ChatMessage::factory()->create([
            'chat_conversation_id' => $conversation->id,
            'role'                 => ChatMessage::ROLE_ASSISTANT,
            'status'               => ChatMessage::STATUS_COMPLETED,
            'content'              => 'First answer',
            'sequence_number'      => 2,
        ]);
        $aiRequest = ChatAiRequest::factory()->create([
            'chat_conversation_id' => $conversation->id,
            'user_message_id'      => $userMsg->id,
            'assistant_message_id' => $assistantMsg->id,
            'status'               => ChatAiRequest::STATUS_COMPLETED,
            'attempt_number'       => 1,
        ]);

        return compact('conversation', 'userMsg', 'assistantMsg', 'aiRequest');
    }

    // ── auth ──────────────────────────────────────────────────────────────────

    public function test_unauthenticated_request_returns_401_for_all_chat_endpoints(): void
    {
        $uuid = \Illuminate\Support\Str::uuid();

        $this->postJson('/api/v1/student/chats')->assertStatus(401);
        $this->getJson('/api/v1/student/chats')->assertStatus(401);
        $this->getJson("/api/v1/student/chats/{$uuid}")->assertStatus(401);
        $this->patchJson("/api/v1/student/chats/{$uuid}")->assertStatus(401);
        $this->deleteJson("/api/v1/student/chats/{$uuid}")->assertStatus(401);
        $this->postJson("/api/v1/student/chats/{$uuid}/messages")->assertStatus(401);
        $this->getJson("/api/v1/student/chats/{$uuid}/messages/{$uuid}/status")->assertStatus(401);
        $this->postJson("/api/v1/student/chats/{$uuid}/messages/{$uuid}/retry")->assertStatus(401);
    }

    // ── store() ───────────────────────────────────────────────────────────────

    public function test_store_creates_chat_and_returns_202(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);

        $response = $this->postJson('/api/v1/student/chats', $this->chatPayload('Hello AI'));

        $response->assertStatus(202)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'chat'              => ['uuid', 'title', 'last_message_at'],
                    'user_message'      => ['uuid', 'role', 'content', 'status', 'sequence_number'],
                    'assistant_message' => ['uuid', 'role', 'content', 'status', 'sequence_number'],
                    'ai_request'        => ['uuid', 'status', 'attempt_number'],
                ],
            ])
            ->assertJson(['success' => true]);
    }

    public function test_store_creates_exactly_one_conversation(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);

        $this->postJson('/api/v1/student/chats', $this->chatPayload());

        $this->assertDatabaseCount('chat_conversations', 1);
    }

    public function test_store_user_message_is_sequence_1_and_completed(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);

        $this->postJson('/api/v1/student/chats', $this->chatPayload('My question'));

        $this->assertDatabaseHas('chat_messages', [
            'role'            => ChatMessage::ROLE_USER,
            'content'         => 'My question',
            'status'          => ChatMessage::STATUS_COMPLETED,
            'sequence_number' => 1,
        ]);
    }

    public function test_store_assistant_placeholder_is_sequence_2_pending_null_content(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);

        $this->postJson('/api/v1/student/chats', $this->chatPayload());

        $this->assertDatabaseHas('chat_messages', [
            'role'            => ChatMessage::ROLE_ASSISTANT,
            'content'         => null,
            'status'          => ChatMessage::STATUS_PENDING,
            'sequence_number' => 2,
        ]);
    }

    public function test_store_creates_queued_ai_request_with_attempt_1(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);

        $this->postJson('/api/v1/student/chats', $this->chatPayload());

        $this->assertDatabaseHas('chat_ai_requests', [
            'status'         => ChatAiRequest::STATUS_QUEUED,
            'attempt_number' => 1,
        ]);
    }

    public function test_store_title_uses_str_limit_without_ellipsis(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);

        $message = str_repeat('a', 80);
        $this->postJson('/api/v1/student/chats', $this->chatPayload($message));

        $this->assertDatabaseHas('chat_conversations', [
            'title' => str_repeat('a', 50),
        ]);
    }

    public function test_store_title_unicode_arabic_truncation(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);

        $arabic  = str_repeat('م', 60);
        $this->postJson('/api/v1/student/chats', $this->chatPayload($arabic));

        $conv = ChatConversation::first();
        // mb_strlen returns correct character count for multi-byte strings
        $this->assertLessThanOrEqual(50, mb_strlen($conv->title));
    }

    public function test_store_requires_client_message_id(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);

        $this->postJson('/api/v1/student/chats', ['message' => 'Hello'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['client_message_id']);
    }

    public function test_store_requires_valid_uuid_for_client_message_id(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);

        $this->postJson('/api/v1/student/chats', [
            'message'           => 'Hello',
            'client_message_id' => 'not-a-uuid',
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['client_message_id']);
    }

    public function test_store_rejects_message_over_max_length(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);

        $this->postJson('/api/v1/student/chats', [
            'message'           => str_repeat('x', 4001),
            'client_message_id' => (string) \Illuminate\Support\Str::uuid(),
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['message']);
    }

    // ── store() idempotency ───────────────────────────────────────────────────

    public function test_same_student_duplicate_visible_client_message_id_returns_202(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);
        $uid = (string) \Illuminate\Support\Str::uuid();

        $this->postJson('/api/v1/student/chats', $this->chatPayload('First', $uid))->assertStatus(202);
        $this->postJson('/api/v1/student/chats', $this->chatPayload('First', $uid))->assertStatus(202);

        $this->assertDatabaseCount('chat_conversations', 1);
    }

    public function test_same_student_duplicate_hidden_client_message_id_returns_409(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);
        $uid = (string) \Illuminate\Support\Str::uuid();

        $this->postJson('/api/v1/student/chats', $this->chatPayload('First', $uid))->assertStatus(202);

        // Hide the conversation
        ChatConversation::first()->update(['deleted_by_student_at' => now()]);

        $this->postJson('/api/v1/student/chats', $this->chatPayload('First', $uid))
            ->assertStatus(409)
            ->assertJson(['success' => false, 'message' => 'Message ID already used.']);
    }

    public function test_different_student_same_client_message_id_returns_409(): void
    {
        $studentA = $this->makeStudent();
        $studentB = $this->makeStudent();
        $uid      = (string) \Illuminate\Support\Str::uuid();

        Sanctum::actingAs($studentA);
        $this->postJson('/api/v1/student/chats', $this->chatPayload('Hello', $uid))->assertStatus(202);

        Sanctum::actingAs($studentB);
        $this->postJson('/api/v1/student/chats', $this->chatPayload('Hello', $uid))
            ->assertStatus(409)
            ->assertJson(['success' => false, 'message' => 'Message ID already used.']);
    }

    public function test_same_student_idempotent_replay_returns_real_progressed_status(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);
        $uid = (string) \Illuminate\Support\Str::uuid();

        // Step 1: create cycle
        $first = $this->postJson('/api/v1/student/chats', $this->chatPayload('My question', $uid))
            ->assertStatus(202);

        $assistantUuid = $first->json('data.assistant_message.uuid');
        $aiRequestUuid = $first->json('data.ai_request.uuid');
        $knownContent  = 'The AI replied with this specific content.';

        // Steps 2 + 3: advance the cycle to completed
        ChatMessage::where('uuid', $assistantUuid)->update([
            'status'  => ChatMessage::STATUS_COMPLETED,
            'content' => $knownContent,
        ]);
        ChatAiRequest::where('uuid', $aiRequestUuid)->update([
            'status' => ChatAiRequest::STATUS_COMPLETED,
        ]);

        // Capture counts before replay
        $convCount  = ChatConversation::count();
        $msgCount   = ChatMessage::count();
        $aiReqCount = ChatAiRequest::count();

        // Step 4: replay same client_message_id
        $replay = $this->postJson('/api/v1/student/chats', $this->chatPayload('My question', $uid))
            ->assertStatus(202);

        // Steps 5-8: real current values are returned
        $this->assertSame($knownContent,                   $replay->json('data.assistant_message.content'));
        $this->assertSame(ChatMessage::STATUS_COMPLETED,   $replay->json('data.assistant_message.status'));
        $this->assertSame(ChatAiRequest::STATUS_COMPLETED, $replay->json('data.ai_request.status'));

        // Step 9: no extra records or dispatches
        $this->assertSame($convCount,  ChatConversation::count());
        $this->assertSame($msgCount,   ChatMessage::count());
        $this->assertSame($aiReqCount, ChatAiRequest::count());
        Queue::assertPushed(ProcessStudentAiChat::class, 1);
    }

    public function test_collision_response_does_not_leak_other_student_data(): void
    {
        $studentA = $this->makeStudent();
        $studentB = $this->makeStudent();
        $uid      = (string) \Illuminate\Support\Str::uuid();

        Sanctum::actingAs($studentA);
        $this->postJson('/api/v1/student/chats', $this->chatPayload('Secret message', $uid));

        Sanctum::actingAs($studentB);
        $response = $this->postJson('/api/v1/student/chats', $this->chatPayload('Secret message', $uid));

        $body = $response->json();
        $this->assertArrayNotHasKey('data', $body);
        $this->assertArrayNotHasKey('chat', $body);
    }

    // ── index() ───────────────────────────────────────────────────────────────

    public function test_index_returns_only_owned_visible_conversations(): void
    {
        $student = $this->makeStudent();
        $other   = $this->makeStudent();
        Sanctum::actingAs($student);

        ChatConversation::factory()->create(['student_id' => $student->id]);
        ChatConversation::factory()->create(['student_id' => $student->id, 'deleted_by_student_at' => now()]);
        ChatConversation::factory()->create(['student_id' => $other->id]);

        $response = $this->getJson('/api/v1/student/chats')->assertStatus(200);

        $response->assertJsonStructure([
            'success',
            'data' => [
                'conversations',
                'pagination' => ['current_page', 'last_page', 'per_page', 'total'],
            ],
        ]);
        $this->assertCount(1, $response->json('data.conversations'));
    }

    public function test_index_orders_conversations_by_last_message_at_desc(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);

        $older = ChatConversation::factory()->create(['student_id' => $student->id, 'last_message_at' => now()->subHour()]);
        $newer = ChatConversation::factory()->create(['student_id' => $student->id, 'last_message_at' => now()]);

        $response = $this->getJson('/api/v1/student/chats')->assertStatus(200);

        $uuids = array_column($response->json('data.conversations'), 'uuid');
        $this->assertSame($newer->uuid, $uuids[0]);
        $this->assertSame($older->uuid, $uuids[1]);
    }

    public function test_index_returns_pagination_metadata(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);

        ChatConversation::factory()->count(3)->create(['student_id' => $student->id]);

        $response = $this->getJson('/api/v1/student/chats')->assertStatus(200);

        $pagination = $response->json('data.pagination');
        $this->assertSame(1,  $pagination['current_page']);
        $this->assertSame(1,  $pagination['last_page']);
        $this->assertSame(20, $pagination['per_page']);
        $this->assertSame(3,  $pagination['total']);
    }

    public function test_index_returns_maximum_20_conversations_per_page(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);

        ChatConversation::factory()->count(25)->create(['student_id' => $student->id]);

        $response = $this->getJson('/api/v1/student/chats')->assertStatus(200);

        $this->assertCount(20, $response->json('data.conversations'));
        $this->assertSame(2,  $response->json('data.pagination.last_page'));
        $this->assertSame(25, $response->json('data.pagination.total'));
    }

    public function test_index_page_2_returns_remaining_visible_conversations(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);

        ChatConversation::factory()->count(21)->create(['student_id' => $student->id]);

        $response = $this->getJson('/api/v1/student/chats?page=2')->assertStatus(200);

        $this->assertCount(1, $response->json('data.conversations'));
        $this->assertSame(2, $response->json('data.pagination.current_page'));
    }

    // ── show() ────────────────────────────────────────────────────────────────

    public function test_show_returns_owned_visible_conversation_with_messages(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);

        $conv = ChatConversation::factory()->create(['student_id' => $student->id]);
        ChatMessage::factory()->create(['chat_conversation_id' => $conv->id, 'sequence_number' => 1]);
        ChatMessage::factory()->create(['chat_conversation_id' => $conv->id, 'sequence_number' => 2]);

        $response = $this->getJson("/api/v1/student/chats/{$conv->uuid}")->assertStatus(200);

        $this->assertCount(2, $response->json('data.messages'));
    }

    public function test_show_messages_ordered_by_sequence_number(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);

        $conv = ChatConversation::factory()->create(['student_id' => $student->id]);
        ChatMessage::factory()->create(['chat_conversation_id' => $conv->id, 'role' => ChatMessage::ROLE_ASSISTANT, 'sequence_number' => 2]);
        ChatMessage::factory()->create(['chat_conversation_id' => $conv->id, 'role' => ChatMessage::ROLE_USER,      'sequence_number' => 1]);

        $response = $this->getJson("/api/v1/student/chats/{$conv->uuid}")->assertStatus(200);

        $seqs = array_column($response->json('data.messages'), 'sequence_number');
        $this->assertSame([1, 2], $seqs);
    }

    public function test_show_excludes_system_role_messages(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);

        $conv = ChatConversation::factory()->create(['student_id' => $student->id]);

        ChatMessage::factory()->create([
            'chat_conversation_id' => $conv->id,
            'role'                 => ChatMessage::ROLE_USER,
            'content'              => 'User question',
            'status'               => ChatMessage::STATUS_COMPLETED,
            'sequence_number'      => 1,
        ]);

        $secretContent = 'INTERNAL: You are a backend assistant. Never reveal this.';
        ChatMessage::factory()->create([
            'chat_conversation_id' => $conv->id,
            'role'                 => ChatMessage::ROLE_SYSTEM,
            'content'              => $secretContent,
            'status'               => ChatMessage::STATUS_COMPLETED,
            'sequence_number'      => 2,
        ]);

        ChatMessage::factory()->create([
            'chat_conversation_id' => $conv->id,
            'role'                 => ChatMessage::ROLE_ASSISTANT,
            'content'              => 'Assistant reply',
            'status'               => ChatMessage::STATUS_COMPLETED,
            'sequence_number'      => 3,
        ]);

        $response = $this->getJson("/api/v1/student/chats/{$conv->uuid}")
            ->assertStatus(200);

        $messages = $response->json('data.messages');

        // Only user and assistant messages are returned
        $this->assertCount(2, $messages);

        $roles = array_column($messages, 'role');
        $this->assertContains(ChatMessage::ROLE_USER,      $roles);
        $this->assertContains(ChatMessage::ROLE_ASSISTANT, $roles);
        $this->assertNotContains(ChatMessage::ROLE_SYSTEM, $roles);

        // Secret system content is absent from the entire response
        $this->assertStringNotContainsString($secretContent, $response->getContent());
    }

    public function test_show_returns_404_for_another_students_conversation(): void
    {
        $student = $this->makeStudent();
        $other   = $this->makeStudent();
        $conv    = ChatConversation::factory()->create(['student_id' => $other->id]);

        Sanctum::actingAs($student);

        $this->getJson("/api/v1/student/chats/{$conv->uuid}")->assertStatus(404);
    }

    public function test_show_returns_404_for_hidden_conversation(): void
    {
        $student = $this->makeStudent();
        $conv    = ChatConversation::factory()->create(['student_id' => $student->id, 'deleted_by_student_at' => now()]);

        Sanctum::actingAs($student);

        $this->getJson("/api/v1/student/chats/{$conv->uuid}")->assertStatus(404);
    }

    // ── update() ─────────────────────────────────────────────────────────────

    public function test_update_renames_owned_visible_conversation(): void
    {
        $student = $this->makeStudent();
        $conv    = ChatConversation::factory()->create(['student_id' => $student->id]);
        Sanctum::actingAs($student);

        $this->patchJson("/api/v1/student/chats/{$conv->uuid}", ['title' => 'New Title'])
            ->assertStatus(200)
            ->assertJsonFragment(['title' => 'New Title']);

        $this->assertDatabaseHas('chat_conversations', ['uuid' => $conv->uuid, 'title' => 'New Title']);
    }

    public function test_update_returns_404_for_another_students_conversation(): void
    {
        $student = $this->makeStudent();
        $other   = $this->makeStudent();
        $conv    = ChatConversation::factory()->create(['student_id' => $other->id]);

        Sanctum::actingAs($student);
        $this->patchJson("/api/v1/student/chats/{$conv->uuid}", ['title' => 'Hack'])->assertStatus(404);
    }

    public function test_update_requires_title(): void
    {
        $student = $this->makeStudent();
        $conv    = ChatConversation::factory()->create(['student_id' => $student->id]);
        Sanctum::actingAs($student);

        $this->patchJson("/api/v1/student/chats/{$conv->uuid}", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    }

    // ── destroy() ─────────────────────────────────────────────────────────────

    public function test_destroy_sets_deleted_by_student_at(): void
    {
        $student = $this->makeStudent();
        $conv    = ChatConversation::factory()->create(['student_id' => $student->id]);
        Sanctum::actingAs($student);

        $this->deleteJson("/api/v1/student/chats/{$conv->uuid}")->assertStatus(200);

        $this->assertDatabaseMissing('chat_conversations', ['uuid' => $conv->uuid, 'deleted_by_student_at' => null]);
    }

    public function test_destroy_returns_404_for_another_students_conversation(): void
    {
        $student = $this->makeStudent();
        $other   = $this->makeStudent();
        $conv    = ChatConversation::factory()->create(['student_id' => $other->id]);

        Sanctum::actingAs($student);
        $this->deleteJson("/api/v1/student/chats/{$conv->uuid}")->assertStatus(404);
    }

    // ── hidden-chat protection ────────────────────────────────────────────────

    public function test_hidden_conversation_returns_404_on_show(): void
    {
        $student = $this->makeStudent();
        $conv    = ChatConversation::factory()->create(['student_id' => $student->id, 'deleted_by_student_at' => now()]);
        Sanctum::actingAs($student);

        $this->getJson("/api/v1/student/chats/{$conv->uuid}")->assertStatus(404);
    }

    public function test_hidden_conversation_returns_404_on_rename(): void
    {
        $student = $this->makeStudent();
        $conv    = ChatConversation::factory()->create(['student_id' => $student->id, 'deleted_by_student_at' => now()]);
        Sanctum::actingAs($student);

        $this->patchJson("/api/v1/student/chats/{$conv->uuid}", ['title' => 'x'])->assertStatus(404);
    }

    public function test_hidden_conversation_returns_404_on_send_message(): void
    {
        $student = $this->makeStudent();
        $conv    = ChatConversation::factory()->create(['student_id' => $student->id, 'deleted_by_student_at' => now()]);
        Sanctum::actingAs($student);

        $this->postJson("/api/v1/student/chats/{$conv->uuid}/messages", $this->chatPayload())->assertStatus(404);
    }

    public function test_hidden_conversation_returns_404_on_message_status(): void
    {
        $student  = $this->makeStudent();
        $conv     = ChatConversation::factory()->create(['student_id' => $student->id, 'deleted_by_student_at' => now()]);
        $msgUuid  = (string) \Illuminate\Support\Str::uuid();
        Sanctum::actingAs($student);

        $this->getJson("/api/v1/student/chats/{$conv->uuid}/messages/{$msgUuid}/status")->assertStatus(404);
    }

    public function test_hidden_conversation_returns_404_on_retry(): void
    {
        $student = $this->makeStudent();
        $conv    = ChatConversation::factory()->create(['student_id' => $student->id, 'deleted_by_student_at' => now()]);
        $msgUuid = (string) \Illuminate\Support\Str::uuid();
        Sanctum::actingAs($student);

        $this->postJson("/api/v1/student/chats/{$conv->uuid}/messages/{$msgUuid}/retry")->assertStatus(404);
    }

    // ── sendMessage() ─────────────────────────────────────────────────────────

    public function test_send_message_creates_new_turn_and_returns_202(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);
        ['conversation' => $conv] = $this->makeConversationWithCompletedTurn($student);

        $response = $this->postJson("/api/v1/student/chats/{$conv->uuid}/messages", $this->chatPayload('Second question'))
            ->assertStatus(202);

        $this->assertNotNull($response->json('data.user_message.uuid'));
        $this->assertEquals(ChatMessage::STATUS_PENDING, $response->json('data.assistant_message.status'));
    }

    public function test_send_message_increments_sequence_numbers(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);
        ['conversation' => $conv] = $this->makeConversationWithCompletedTurn($student);

        $this->postJson("/api/v1/student/chats/{$conv->uuid}/messages", $this->chatPayload());

        $this->assertDatabaseHas('chat_messages', ['chat_conversation_id' => $conv->id, 'sequence_number' => 3]);
        $this->assertDatabaseHas('chat_messages', ['chat_conversation_id' => $conv->id, 'sequence_number' => 4]);
    }

    public function test_send_message_rejected_when_pending_assistant_exists(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);

        $conv = ChatConversation::factory()->create(['student_id' => $student->id]);
        ChatMessage::factory()->create([
            'chat_conversation_id' => $conv->id,
            'role'                 => ChatMessage::ROLE_ASSISTANT,
            'status'               => ChatMessage::STATUS_PENDING,
            'sequence_number'      => 2,
        ]);

        $this->postJson("/api/v1/student/chats/{$conv->uuid}/messages", $this->chatPayload())
            ->assertStatus(409);
    }

    public function test_send_message_idempotency_same_visible_returns_202(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);
        ['conversation' => $conv] = $this->makeConversationWithCompletedTurn($student);

        $uid = (string) \Illuminate\Support\Str::uuid();
        $this->postJson("/api/v1/student/chats/{$conv->uuid}/messages", $this->chatPayload('Msg', $uid))->assertStatus(202);
        $this->postJson("/api/v1/student/chats/{$conv->uuid}/messages", $this->chatPayload('Msg', $uid))->assertStatus(202);

        // Only one new AI request should have been created (the second was idempotent)
        $this->assertDatabaseCount('chat_ai_requests', 2); // 1 from setup + 1 new
    }

    public function test_send_message_idempotency_hidden_returns_409(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);

        ['conversation' => $convA] = $this->makeConversationWithCompletedTurn($student);
        $uid = (string) \Illuminate\Support\Str::uuid();
        $this->postJson("/api/v1/student/chats/{$convA->uuid}/messages", $this->chatPayload('Msg', $uid))->assertStatus(202);

        // Hide the conversation that contains this message
        ChatConversation::whereHas('messages', fn ($q) => $q->where('client_message_id', $uid))
            ->update(['deleted_by_student_at' => now()]);

        ['conversation' => $convB] = $this->makeConversationWithCompletedTurn($student);
        $this->postJson("/api/v1/student/chats/{$convB->uuid}/messages", $this->chatPayload('Msg', $uid))
            ->assertStatus(409)
            ->assertJson(['success' => false, 'message' => 'Message ID already used.']);
    }

    // ── messageStatus() ───────────────────────────────────────────────────────

    public function test_message_status_returns_assistant_and_latest_ai_request(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);

        $conv     = ChatConversation::factory()->create(['student_id' => $student->id]);
        $userMsg  = ChatMessage::factory()->create(['chat_conversation_id' => $conv->id, 'role' => ChatMessage::ROLE_USER,      'sequence_number' => 1]);
        $assistMsg = ChatMessage::factory()->create(['chat_conversation_id' => $conv->id, 'role' => ChatMessage::ROLE_ASSISTANT, 'sequence_number' => 2, 'status' => ChatMessage::STATUS_PENDING, 'content' => null]);

        ChatAiRequest::factory()->create([
            'chat_conversation_id' => $conv->id,
            'user_message_id'      => $userMsg->id,
            'assistant_message_id' => $assistMsg->id,
            'status'               => ChatAiRequest::STATUS_QUEUED,
            'attempt_number'       => 1,
        ]);

        $response = $this->getJson("/api/v1/student/chats/{$conv->uuid}/messages/{$assistMsg->uuid}/status")
            ->assertStatus(200)
            ->assertJsonStructure(['success', 'data' => ['assistant_message', 'ai_request']]);

        $this->assertEquals(ChatMessage::STATUS_PENDING,      $response->json('data.assistant_message.status'));
        $this->assertEquals(ChatAiRequest::STATUS_QUEUED,     $response->json('data.ai_request.status'));
        $this->assertEquals(1,                                $response->json('data.ai_request.attempt_number'));
    }

    public function test_message_status_returns_latest_attempt_by_attempt_number(): void
    {
        $student  = $this->makeStudent();
        $conv     = ChatConversation::factory()->create(['student_id' => $student->id]);
        $userMsg  = ChatMessage::factory()->create(['chat_conversation_id' => $conv->id, 'role' => ChatMessage::ROLE_USER,      'sequence_number' => 1]);
        $assistMsg = ChatMessage::factory()->create(['chat_conversation_id' => $conv->id, 'role' => ChatMessage::ROLE_ASSISTANT, 'sequence_number' => 2, 'status' => ChatMessage::STATUS_PENDING]);

        ChatAiRequest::factory()->create(['chat_conversation_id' => $conv->id, 'user_message_id' => $userMsg->id, 'assistant_message_id' => $assistMsg->id, 'status' => ChatAiRequest::STATUS_FAILED,    'attempt_number' => 1]);
        ChatAiRequest::factory()->create(['chat_conversation_id' => $conv->id, 'user_message_id' => $userMsg->id, 'assistant_message_id' => $assistMsg->id, 'status' => ChatAiRequest::STATUS_QUEUED,    'attempt_number' => 2]);

        Sanctum::actingAs($student);
        $response = $this->getJson("/api/v1/student/chats/{$conv->uuid}/messages/{$assistMsg->uuid}/status")
            ->assertStatus(200);

        $this->assertEquals(2, $response->json('data.ai_request.attempt_number'));
    }

    public function test_message_status_returns_404_for_another_students_conversation(): void
    {
        $student  = $this->makeStudent();
        $other    = $this->makeStudent();
        $conv     = ChatConversation::factory()->create(['student_id' => $other->id]);
        $msgUuid  = (string) \Illuminate\Support\Str::uuid();

        Sanctum::actingAs($student);
        $this->getJson("/api/v1/student/chats/{$conv->uuid}/messages/{$msgUuid}/status")->assertStatus(404);
    }

    // ── retryMessage() ────────────────────────────────────────────────────────

    private function makeFailedCycle(Student $student): array
    {
        $conv      = ChatConversation::factory()->create(['student_id' => $student->id]);
        $userMsg   = ChatMessage::factory()->create(['chat_conversation_id' => $conv->id, 'role' => ChatMessage::ROLE_USER,      'status' => ChatMessage::STATUS_COMPLETED, 'sequence_number' => 1]);
        $assistMsg = ChatMessage::factory()->create(['chat_conversation_id' => $conv->id, 'role' => ChatMessage::ROLE_ASSISTANT, 'status' => ChatMessage::STATUS_FAILED,    'sequence_number' => 2]);
        $aiReq     = ChatAiRequest::factory()->create([
            'chat_conversation_id' => $conv->id,
            'user_message_id'      => $userMsg->id,
            'assistant_message_id' => $assistMsg->id,
            'status'               => ChatAiRequest::STATUS_FAILED,
            'attempt_number'       => 1,
        ]);

        return compact('conv', 'userMsg', 'assistMsg', 'aiReq');
    }

    public function test_retry_creates_new_ai_request_with_incremented_attempt(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);
        ['conv' => $conv, 'assistMsg' => $assistMsg] = $this->makeFailedCycle($student);

        $this->postJson("/api/v1/student/chats/{$conv->uuid}/messages/{$assistMsg->uuid}/retry")
            ->assertStatus(202);

        $this->assertDatabaseHas('chat_ai_requests', ['attempt_number' => 2, 'status' => ChatAiRequest::STATUS_QUEUED]);
    }

    public function test_retry_resets_assistant_to_pending_with_null_content(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);
        ['conv' => $conv, 'assistMsg' => $assistMsg] = $this->makeFailedCycle($student);

        $this->postJson("/api/v1/student/chats/{$conv->uuid}/messages/{$assistMsg->uuid}/retry")
            ->assertStatus(202);

        $this->assertDatabaseHas('chat_messages', [
            'id'      => $assistMsg->id,
            'status'  => ChatMessage::STATUS_PENDING,
            'content' => null,
        ]);
    }

    public function test_retry_reuses_original_user_message(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);
        ['conv' => $conv, 'userMsg' => $userMsg, 'assistMsg' => $assistMsg] = $this->makeFailedCycle($student);

        $response = $this->postJson("/api/v1/student/chats/{$conv->uuid}/messages/{$assistMsg->uuid}/retry")
            ->assertStatus(202);

        $this->assertEquals($userMsg->uuid, $response->json('data.user_message.uuid'));
    }

    public function test_retry_blocked_when_assistant_status_not_failed(): void
    {
        $student   = $this->makeStudent();
        $conv      = ChatConversation::factory()->create(['student_id' => $student->id]);
        $userMsg   = ChatMessage::factory()->create(['chat_conversation_id' => $conv->id, 'role' => ChatMessage::ROLE_USER,      'sequence_number' => 1]);
        $assistMsg = ChatMessage::factory()->create([
            'chat_conversation_id' => $conv->id,
            'role'                 => ChatMessage::ROLE_ASSISTANT,
            'status'               => ChatMessage::STATUS_PENDING, // not failed
            'sequence_number'      => 2,
        ]);
        ChatAiRequest::factory()->create([
            'chat_conversation_id' => $conv->id,
            'user_message_id'      => $userMsg->id,
            'assistant_message_id' => $assistMsg->id,
            'status'               => ChatAiRequest::STATUS_QUEUED,
            'attempt_number'       => 1,
        ]);

        Sanctum::actingAs($student);
        $this->postJson("/api/v1/student/chats/{$conv->uuid}/messages/{$assistMsg->uuid}/retry")
            ->assertStatus(422);
    }

    public function test_retry_blocked_when_active_attempt_exists(): void
    {
        $student   = $this->makeStudent();
        $conv      = ChatConversation::factory()->create(['student_id' => $student->id]);
        $userMsg   = ChatMessage::factory()->create(['chat_conversation_id' => $conv->id, 'role' => ChatMessage::ROLE_USER,      'sequence_number' => 1]);
        $assistMsg = ChatMessage::factory()->create(['chat_conversation_id' => $conv->id, 'role' => ChatMessage::ROLE_ASSISTANT, 'status' => ChatMessage::STATUS_FAILED, 'sequence_number' => 2]);

        // First attempt failed
        ChatAiRequest::factory()->create(['chat_conversation_id' => $conv->id, 'user_message_id' => $userMsg->id, 'assistant_message_id' => $assistMsg->id, 'status' => ChatAiRequest::STATUS_FAILED,  'attempt_number' => 1]);
        // Second attempt is queued — should block retry
        ChatAiRequest::factory()->create(['chat_conversation_id' => $conv->id, 'user_message_id' => $userMsg->id, 'assistant_message_id' => $assistMsg->id, 'status' => ChatAiRequest::STATUS_QUEUED,  'attempt_number' => 2]);

        Sanctum::actingAs($student);
        $this->postJson("/api/v1/student/chats/{$conv->uuid}/messages/{$assistMsg->uuid}/retry")
            ->assertStatus(409);
    }

    // ── Job — handle() (direct execution) ────────────────────────────────────

    public function test_job_success_updates_assistant_content_and_ai_request(): void
    {
        $fake    = $this->makeFakeClient('success');
        $student = $this->makeStudent();
        $conv    = ChatConversation::factory()->create(['student_id' => $student->id]);
        $userMsg = ChatMessage::factory()->create(['chat_conversation_id' => $conv->id, 'role' => ChatMessage::ROLE_USER, 'sequence_number' => 1]);
        $assistMsg = ChatMessage::factory()->create([
            'chat_conversation_id' => $conv->id,
            'role'                 => ChatMessage::ROLE_ASSISTANT,
            'status'               => ChatMessage::STATUS_PENDING,
            'content'              => null,
            'sequence_number'      => 2,
        ]);
        $aiReq = ChatAiRequest::factory()->create([
            'chat_conversation_id' => $conv->id,
            'user_message_id'      => $userMsg->id,
            'assistant_message_id' => $assistMsg->id,
            'status'               => ChatAiRequest::STATUS_QUEUED,
            'attempt_number'       => 1,
        ]);

        $job = new ProcessStudentAiChat($aiReq->id);
        $job->handle($fake);

        $this->assertDatabaseHas('chat_messages', [
            'id'      => $assistMsg->id,
            'status'  => ChatMessage::STATUS_COMPLETED,
            'content' => 'AI response content',
        ]);
        $this->assertDatabaseHas('chat_ai_requests', [
            'id'     => $aiReq->id,
            'status' => ChatAiRequest::STATUS_COMPLETED,
        ]);
        $this->assertNotNull(ChatAiRequest::find($aiReq->id)->completed_at);
    }

    public function test_job_failure_marks_assistant_and_ai_request_failed(): void
    {
        $fake    = $this->makeFakeClient('failure');
        $student = $this->makeStudent();
        $conv    = ChatConversation::factory()->create(['student_id' => $student->id]);
        $userMsg = ChatMessage::factory()->create(['chat_conversation_id' => $conv->id, 'role' => ChatMessage::ROLE_USER, 'sequence_number' => 1]);
        $assistMsg = ChatMessage::factory()->create([
            'chat_conversation_id' => $conv->id,
            'role'                 => ChatMessage::ROLE_ASSISTANT,
            'status'               => ChatMessage::STATUS_PENDING,
            'content'              => null,
            'sequence_number'      => 2,
        ]);
        $aiReq = ChatAiRequest::factory()->create([
            'chat_conversation_id' => $conv->id,
            'user_message_id'      => $userMsg->id,
            'assistant_message_id' => $assistMsg->id,
            'status'               => ChatAiRequest::STATUS_QUEUED,
            'attempt_number'       => 1,
        ]);

        $job = new ProcessStudentAiChat($aiReq->id);
        try {
            $job->handle($fake);
        } catch (AiClientException $e) {
            $job->failed($e);
        }

        $this->assertDatabaseHas('chat_messages',    ['id' => $assistMsg->id, 'status' => ChatMessage::STATUS_FAILED]);
        $this->assertDatabaseHas('chat_ai_requests', ['id' => $aiReq->id,     'status' => ChatAiRequest::STATUS_FAILED, 'error_code' => 'AI_ERROR']);
        $this->assertNotNull(ChatAiRequest::find($aiReq->id)->failed_at);
    }

    public function test_job_timeout_sets_timeout_error_code(): void
    {
        $student   = $this->makeStudent();
        $conv      = ChatConversation::factory()->create(['student_id' => $student->id]);
        $userMsg   = ChatMessage::factory()->create(['chat_conversation_id' => $conv->id, 'role' => ChatMessage::ROLE_USER, 'sequence_number' => 1]);
        $assistMsg = ChatMessage::factory()->create(['chat_conversation_id' => $conv->id, 'role' => ChatMessage::ROLE_ASSISTANT, 'status' => ChatMessage::STATUS_PENDING, 'content' => null, 'sequence_number' => 2]);
        $aiReq     = ChatAiRequest::factory()->create(['chat_conversation_id' => $conv->id, 'user_message_id' => $userMsg->id, 'assistant_message_id' => $assistMsg->id, 'status' => ChatAiRequest::STATUS_QUEUED, 'attempt_number' => 1]);

        $job = new ProcessStudentAiChat($aiReq->id);
        $job->failed(new AiClientException('TIMEOUT', 'The AI request timed out.'));

        $this->assertDatabaseHas('chat_ai_requests', ['id' => $aiReq->id, 'error_code' => 'TIMEOUT']);
    }

    public function test_job_invalid_response_shape_sets_invalid_ai_response_error_code(): void
    {
        $fake = new FakeStudentAiChatClient();
        $this->app->instance(StudentAiChatClientContract::class, $fake);

        $student   = $this->makeStudent();
        $conv      = ChatConversation::factory()->create(['student_id' => $student->id]);
        $userMsg   = ChatMessage::factory()->create(['chat_conversation_id' => $conv->id, 'role' => ChatMessage::ROLE_USER, 'sequence_number' => 1]);
        $assistMsg = ChatMessage::factory()->create(['chat_conversation_id' => $conv->id, 'role' => ChatMessage::ROLE_ASSISTANT, 'status' => ChatMessage::STATUS_PENDING, 'content' => null, 'sequence_number' => 2]);
        $aiReq     = ChatAiRequest::factory()->create(['chat_conversation_id' => $conv->id, 'user_message_id' => $userMsg->id, 'assistant_message_id' => $assistMsg->id, 'status' => ChatAiRequest::STATUS_QUEUED, 'attempt_number' => 1]);

        // Override send() to return invalid shape
        $fakeBadShape = new class extends FakeStudentAiChatClient {
            public function send(array $payload): array
            {
                return ['bad_key' => 'no content key'];
            }
        };
        $this->app->instance(StudentAiChatClientContract::class, $fakeBadShape);

        $job = new ProcessStudentAiChat($aiReq->id);
        try {
            $job->handle($fakeBadShape);
            $this->fail('Expected AiClientException was not thrown.');
        } catch (AiClientException $e) {
            $job->failed($e);
        }

        $this->assertDatabaseHas('chat_ai_requests', ['id' => $aiReq->id, 'error_code' => 'INVALID_AI_RESPONSE']);
    }

    public function test_job_atomic_claim_prevents_double_processing(): void
    {
        $fake    = $this->makeFakeClient('success');
        $student = $this->makeStudent();
        $conv    = ChatConversation::factory()->create(['student_id' => $student->id]);
        $userMsg = ChatMessage::factory()->create(['chat_conversation_id' => $conv->id, 'role' => ChatMessage::ROLE_USER, 'sequence_number' => 1]);
        $assistMsg = ChatMessage::factory()->create(['chat_conversation_id' => $conv->id, 'role' => ChatMessage::ROLE_ASSISTANT, 'status' => ChatMessage::STATUS_PENDING, 'content' => null, 'sequence_number' => 2]);
        $aiReq = ChatAiRequest::factory()->create(['chat_conversation_id' => $conv->id, 'user_message_id' => $userMsg->id, 'assistant_message_id' => $assistMsg->id, 'status' => ChatAiRequest::STATUS_PROCESSING, 'attempt_number' => 1]);

        $job = new ProcessStudentAiChat($aiReq->id);
        $job->handle($fake);

        // Status should still be PROCESSING — atomic claim found 0 rows and returned early
        $this->assertDatabaseHas('chat_ai_requests', ['id' => $aiReq->id, 'status' => ChatAiRequest::STATUS_PROCESSING]);
        $this->assertEquals(0, $fake->getCallCount());
    }

    public function test_job_exits_safely_when_ai_request_not_found(): void
    {
        $fake = $this->makeFakeClient('success');

        $job = new ProcessStudentAiChat(99999);
        $job->handle($fake); // must not throw

        $this->assertEquals(0, $fake->getCallCount());
    }
}
