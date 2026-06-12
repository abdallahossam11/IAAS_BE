<?php

namespace Tests\Feature;

use App\Models\ChatAiRequest;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Student;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChatDatabaseModelTest extends TestCase
{
    use RefreshDatabase;

    // ══════════════════════════════════════════════════════════════════════
    // Private helper — build a coherent conversation + user + assistant set
    // and return an AI request row using forCycle() (no hidden side-effects).
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Create one conversation with one user message, one assistant
     * placeholder, and one ChatAiRequest row linking them via forCycle().
     *
     * @param  array $aiRequestAttributes  Extra attributes merged onto the AI request row.
     * @return array{conversation: ChatConversation, userMessage: ChatMessage, assistantMessage: ChatMessage, aiRequest: ChatAiRequest}
     */
    private function makeAiRequestCycle(array $aiRequestAttributes = []): array
    {
        $conversation = ChatConversation::factory()->create();

        $userMessage = ChatMessage::factory()->create([
            'chat_conversation_id' => $conversation->id,
            'role'                 => ChatMessage::ROLE_USER,
            'status'               => ChatMessage::STATUS_COMPLETED,
            'sequence_number'      => 1,
        ]);

        $assistantMessage = ChatMessage::factory()->pendingAssistant()->create([
            'chat_conversation_id' => $conversation->id,
            'sequence_number'      => 2,
        ]);

        $aiRequest = ChatAiRequest::factory()
            ->forCycle($conversation, $userMessage, $assistantMessage)
            ->create($aiRequestAttributes);

        return compact('conversation', 'userMessage', 'assistantMessage', 'aiRequest');
    }

    // ══════════════════════════════════════════════════════════════════════
    // 1. Schema — all three chatbot tables exist with required columns
    // ══════════════════════════════════════════════════════════════════════

    public function test_chat_conversations_table_has_required_columns(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasColumns('chat_conversations', [
                'id', 'uuid', 'student_id', 'title', 'status',
                'last_message_at', 'deleted_by_student_at',
                'created_at', 'updated_at',
            ])
        );
    }

    public function test_chat_messages_table_has_required_columns(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasColumns('chat_messages', [
                'id', 'uuid', 'chat_conversation_id', 'role', 'content',
                'status', 'sequence_number', 'client_message_id',
                'created_at', 'updated_at',
            ])
        );
    }

    public function test_chat_ai_requests_table_has_required_columns(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasColumns('chat_ai_requests', [
                'id', 'uuid', 'chat_conversation_id', 'user_message_id',
                'assistant_message_id', 'status', 'attempt_number',
                'error_code', 'error_message',
                'submitted_at', 'completed_at', 'failed_at',
                'created_at', 'updated_at',
            ])
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // Factory regression — definition() must contain no nested factories
    // ══════════════════════════════════════════════════════════════════════

    /**
     * The raw definition() must produce null FK columns, not nested factory
     * instances.  This proves that calling ->make() never triggers hidden
     * database writes through nested factory resolution.
     */
    public function test_factory_default_definition_has_null_fk_columns(): void
    {
        // ->make() does not touch the database.  We inspect the raw attribute bag.
        $raw = ChatAiRequest::factory()->make();

        $this->assertNull($raw->chat_conversation_id, 'chat_conversation_id must be null in the default definition');
        $this->assertNull($raw->user_message_id, 'user_message_id must be null in the default definition');
        $this->assertNull($raw->assistant_message_id, 'assistant_message_id must be null in the default definition');
    }

    /**
     * forCycle() must:
     *  - create exactly one AI-request row
     *  - not create extra conversations
     *  - not create extra messages
     *  - link the supplied conversation, user message, and assistant placeholder
     */
    public function test_for_cycle_creates_one_ai_request_and_links_supplied_records(): void
    {
        $conversation = ChatConversation::factory()->create();

        $userMessage = ChatMessage::factory()->create([
            'chat_conversation_id' => $conversation->id,
            'role'                 => ChatMessage::ROLE_USER,
            'status'               => ChatMessage::STATUS_COMPLETED,
            'sequence_number'      => 1,
        ]);

        $assistantMessage = ChatMessage::factory()->pendingAssistant()->create([
            'chat_conversation_id' => $conversation->id,
            'sequence_number'      => 2,
        ]);

        // Exactly one conversation and two messages exist before creating the AI request
        $this->assertDatabaseCount('chat_conversations', 1);
        $this->assertDatabaseCount('chat_messages', 2);
        $this->assertDatabaseCount('chat_ai_requests', 0);

        $aiRequest = ChatAiRequest::factory()
            ->forCycle($conversation, $userMessage, $assistantMessage)
            ->create();

        // forCycle() must not have created extra conversations or messages
        $this->assertDatabaseCount('chat_conversations', 1);
        $this->assertDatabaseCount('chat_messages', 2);
        $this->assertDatabaseCount('chat_ai_requests', 1);

        // The AI request must link the exact records supplied
        $this->assertSame($conversation->id, $aiRequest->chat_conversation_id);
        $this->assertSame($userMessage->id, $aiRequest->user_message_id);
        $this->assertSame($assistantMessage->id, $aiRequest->assistant_message_id);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 2–4. UUID auto-generation — model booted() hook generates the UUID
    // when uuid is explicitly passed as null.
    // ══════════════════════════════════════════════════════════════════════

    public function test_chat_conversation_uuid_is_generated_automatically(): void
    {
        $conversation = ChatConversation::factory()->create(['uuid' => null]);

        $this->assertNotNull($conversation->uuid);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $conversation->uuid
        );
    }

    public function test_chat_conversation_explicit_uuid_is_preserved(): void
    {
        $fixedUuid    = (string) Str::uuid();
        $conversation = ChatConversation::factory()->create(['uuid' => $fixedUuid]);

        $this->assertSame($fixedUuid, $conversation->uuid);
    }

    public function test_chat_message_uuid_is_generated_automatically(): void
    {
        $conversation = ChatConversation::factory()->create();
        $message      = ChatMessage::factory()->create([
            'chat_conversation_id' => $conversation->id,
            'uuid'                 => null,
        ]);

        $this->assertNotNull($message->uuid);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $message->uuid
        );
    }

    public function test_chat_message_explicit_uuid_is_preserved(): void
    {
        $fixedUuid    = (string) Str::uuid();
        $conversation = ChatConversation::factory()->create();
        $message      = ChatMessage::factory()->create([
            'chat_conversation_id' => $conversation->id,
            'uuid'                 => $fixedUuid,
        ]);

        $this->assertSame($fixedUuid, $message->uuid);
    }

    public function test_chat_ai_request_uuid_is_generated_automatically(): void
    {
        ['conversation' => $conv, 'userMessage' => $user, 'assistantMessage' => $assistant]
            = $this->makeAiRequestCycle();

        // Second AI request row (retry) with uuid => null
        $aiRequest = ChatAiRequest::factory()
            ->forCycle($conv, $user, $assistant)
            ->create([
                'uuid'           => null,
                'attempt_number' => 2,
            ]);

        $this->assertNotNull($aiRequest->uuid);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $aiRequest->uuid
        );
    }

    public function test_chat_ai_request_explicit_uuid_is_preserved(): void
    {
        $fixedUuid = (string) Str::uuid();
        ['conversation' => $conv, 'userMessage' => $user, 'assistantMessage' => $assistant]
            = $this->makeAiRequestCycle();

        $aiRequest = ChatAiRequest::factory()
            ->forCycle($conv, $user, $assistant)
            ->create([
                'uuid'           => $fixedUuid,
                'attempt_number' => 2,
            ]);

        $this->assertSame($fixedUuid, $aiRequest->uuid);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 5–11. Eloquent relationships
    // ══════════════════════════════════════════════════════════════════════

    public function test_student_has_many_chat_conversations(): void
    {
        $student = Student::factory()->create();
        ChatConversation::factory()->count(3)->create(['student_id' => $student->id]);

        $this->assertCount(3, $student->chatConversations);
        $this->assertInstanceOf(ChatConversation::class, $student->chatConversations->first());
    }

    public function test_chat_conversation_belongs_to_student(): void
    {
        $student      = Student::factory()->create();
        $conversation = ChatConversation::factory()->create(['student_id' => $student->id]);

        $this->assertTrue($conversation->student->is($student));
    }

    public function test_chat_conversation_has_many_messages(): void
    {
        $conversation = ChatConversation::factory()->create();
        ChatMessage::factory()->count(3)->create([
            'chat_conversation_id' => $conversation->id,
            'sequence_number'      => fn () => fake()->unique()->numberBetween(1, 100),
        ]);

        $this->assertCount(3, $conversation->messages);
        $this->assertInstanceOf(ChatMessage::class, $conversation->messages->first());
    }

    public function test_chat_conversation_has_many_ai_requests(): void
    {
        $conversation = ChatConversation::factory()->create();

        // Two separate turn cycles (different user/assistant pairs)
        foreach ([1, 2] as $i) {
            $user = ChatMessage::factory()->create([
                'chat_conversation_id' => $conversation->id,
                'role'                 => ChatMessage::ROLE_USER,
                'sequence_number'      => ($i * 2) - 1,
            ]);
            $assistant = ChatMessage::factory()->pendingAssistant()->create([
                'chat_conversation_id' => $conversation->id,
                'sequence_number'      => $i * 2,
            ]);
            ChatAiRequest::factory()
                ->forCycle($conversation, $user, $assistant)
                ->create();
        }

        $this->assertCount(2, $conversation->aiRequests);
        $this->assertInstanceOf(ChatAiRequest::class, $conversation->aiRequests->first());
    }

    /**
     * Retry scenario: one conversation, one user message, one assistant
     * placeholder, two ChatAiRequest rows at attempt_number 1 and 2.
     * Both rows reference the same user_message_id.
     */
    public function test_chat_message_ai_requests_as_user_returns_multiple_retry_attempts(): void
    {
        $conversation = ChatConversation::factory()->create();

        $userMessage = ChatMessage::factory()->create([
            'chat_conversation_id' => $conversation->id,
            'role'                 => ChatMessage::ROLE_USER,
            'status'               => ChatMessage::STATUS_COMPLETED,
            'sequence_number'      => 1,
        ]);

        $assistantMessage = ChatMessage::factory()->pendingAssistant()->create([
            'chat_conversation_id' => $conversation->id,
            'sequence_number'      => 2,
        ]);

        // attempt 1 — first try
        ChatAiRequest::factory()
            ->forCycle($conversation, $userMessage, $assistantMessage)
            ->create(['attempt_number' => 1]);

        // attempt 2 — retry; same user message, same assistant placeholder
        ChatAiRequest::factory()
            ->forCycle($conversation, $userMessage, $assistantMessage)
            ->create(['attempt_number' => 2]);

        $this->assertCount(2, $userMessage->aiRequestsAsUser);
    }

    /**
     * Retry scenario: one conversation, one user message, one assistant
     * placeholder, two ChatAiRequest rows at attempt_number 1 and 2.
     * Both rows reference the same assistant_message_id.
     */
    public function test_chat_message_ai_requests_as_assistant_returns_multiple_retry_attempts(): void
    {
        $conversation = ChatConversation::factory()->create();

        $userMessage = ChatMessage::factory()->create([
            'chat_conversation_id' => $conversation->id,
            'role'                 => ChatMessage::ROLE_USER,
            'status'               => ChatMessage::STATUS_COMPLETED,
            'sequence_number'      => 1,
        ]);

        $assistantMessage = ChatMessage::factory()->pendingAssistant()->create([
            'chat_conversation_id' => $conversation->id,
            'sequence_number'      => 2,
        ]);

        // attempt 1 — first try
        ChatAiRequest::factory()
            ->forCycle($conversation, $userMessage, $assistantMessage)
            ->create(['attempt_number' => 1]);

        // attempt 2 — retry; same user message, same assistant placeholder
        ChatAiRequest::factory()
            ->forCycle($conversation, $userMessage, $assistantMessage)
            ->create(['attempt_number' => 2]);

        $this->assertCount(2, $assistantMessage->aiRequestsAsAssistant);
    }

    public function test_chat_ai_request_relationships_resolve_correctly(): void
    {
        $conversation = ChatConversation::factory()->create();

        $userMsg = ChatMessage::factory()->create([
            'chat_conversation_id' => $conversation->id,
            'sequence_number'      => 1,
        ]);
        $assistantMsg = ChatMessage::factory()->pendingAssistant()->create([
            'chat_conversation_id' => $conversation->id,
            'sequence_number'      => 2,
        ]);
        $aiRequest = ChatAiRequest::factory()
            ->forCycle($conversation, $userMsg, $assistantMsg)
            ->create();

        $this->assertTrue($aiRequest->conversation->is($conversation));
        $this->assertTrue($aiRequest->userMessage->is($userMsg));
        $this->assertTrue($aiRequest->assistantMessage->is($assistantMsg));
    }

    // ══════════════════════════════════════════════════════════════════════
    // 12–13. Cascade deletes
    // ══════════════════════════════════════════════════════════════════════

    public function test_deleting_conversation_cascade_deletes_messages(): void
    {
        $conversation = ChatConversation::factory()->create();
        ChatMessage::factory()->create([
            'chat_conversation_id' => $conversation->id,
            'sequence_number'      => 1,
        ]);

        $this->assertDatabaseCount('chat_messages', 1);

        $conversation->delete();

        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function test_deleting_conversation_cascade_deletes_ai_requests(): void
    {
        ['conversation' => $conversation] = $this->makeAiRequestCycle();

        $this->assertDatabaseCount('chat_ai_requests', 1);

        $conversation->delete();

        $this->assertDatabaseCount('chat_ai_requests', 0);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 14. Student deletion restriction
    // ══════════════════════════════════════════════════════════════════════

    public function test_deleting_student_with_conversations_is_rejected_by_database(): void
    {
        $student = Student::factory()->create();
        ChatConversation::factory()->create(['student_id' => $student->id]);

        $this->expectException(QueryException::class);

        $student->delete();
    }

    // ══════════════════════════════════════════════════════════════════════
    // 15. Multiple null client_message_id values are allowed
    // ══════════════════════════════════════════════════════════════════════

    public function test_multiple_assistant_placeholders_may_store_null_client_message_id(): void
    {
        $conversation = ChatConversation::factory()->create();

        ChatMessage::factory()->pendingAssistant()->create([
            'chat_conversation_id' => $conversation->id,
            'sequence_number'      => 1,
            'client_message_id'    => null,
        ]);
        ChatMessage::factory()->pendingAssistant()->create([
            'chat_conversation_id' => $conversation->id,
            'sequence_number'      => 2,
            'client_message_id'    => null,
        ]);

        $this->assertDatabaseCount('chat_messages', 2);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 16. Duplicate non-null client_message_id is rejected globally
    // ══════════════════════════════════════════════════════════════════════

    public function test_duplicate_non_null_client_message_id_is_rejected_globally(): void
    {
        $sharedClientId = (string) Str::uuid();

        $conversation1 = ChatConversation::factory()->create();
        ChatMessage::factory()->create([
            'chat_conversation_id' => $conversation1->id,
            'sequence_number'      => 1,
            'client_message_id'    => $sharedClientId,
        ]);

        $this->expectException(QueryException::class);

        $conversation2 = ChatConversation::factory()->create();
        ChatMessage::factory()->create([
            'chat_conversation_id' => $conversation2->id,
            'sequence_number'      => 1,
            'client_message_id'    => $sharedClientId, // same globally — must be rejected
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 17–18. sequence_number uniqueness scope
    // ══════════════════════════════════════════════════════════════════════

    public function test_duplicate_sequence_number_inside_same_conversation_is_rejected(): void
    {
        $conversation = ChatConversation::factory()->create();

        ChatMessage::factory()->create([
            'chat_conversation_id' => $conversation->id,
            'sequence_number'      => 1,
            'client_message_id'    => (string) Str::uuid(),
        ]);

        $this->expectException(QueryException::class);

        ChatMessage::factory()->create([
            'chat_conversation_id' => $conversation->id,
            'sequence_number'      => 1,                    // duplicate inside same conversation
            'client_message_id'    => (string) Str::uuid(), // distinct client ID — not the problem
        ]);
    }

    public function test_same_sequence_number_is_allowed_in_different_conversations(): void
    {
        $conv1 = ChatConversation::factory()->create();
        $conv2 = ChatConversation::factory()->create();

        ChatMessage::factory()->create([
            'chat_conversation_id' => $conv1->id,
            'sequence_number'      => 1,
            'client_message_id'    => (string) Str::uuid(),
        ]);
        ChatMessage::factory()->create([
            'chat_conversation_id' => $conv2->id,
            'sequence_number'      => 1, // same number, different conversation → OK
            'client_message_id'    => (string) Str::uuid(),
        ]);

        $this->assertDatabaseCount('chat_messages', 2);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 19. visibleToStudent scope
    // ══════════════════════════════════════════════════════════════════════

    public function test_visible_to_student_scope_excludes_hidden_conversations(): void
    {
        $student = Student::factory()->create();

        ChatConversation::factory()->create(['student_id' => $student->id]);
        ChatConversation::factory()->create([
            'student_id'            => $student->id,
            'deleted_by_student_at' => now(),
        ]);

        $visible = ChatConversation::visibleToStudent()
            ->where('student_id', $student->id)
            ->get();

        $this->assertCount(1, $visible);
        $this->assertNull($visible->first()->deleted_by_student_at);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 20. Pending assistant placeholder allows null content
    // ══════════════════════════════════════════════════════════════════════

    public function test_pending_assistant_placeholder_allows_null_content(): void
    {
        $conversation = ChatConversation::factory()->create();
        $message      = ChatMessage::factory()->pendingAssistant()->create([
            'chat_conversation_id' => $conversation->id,
            'sequence_number'      => 1,
        ]);

        $this->assertNull($message->content);
        $this->assertSame(ChatMessage::STATUS_PENDING, $message->status);
        $this->assertSame(ChatMessage::ROLE_ASSISTANT, $message->role);
    }
}
