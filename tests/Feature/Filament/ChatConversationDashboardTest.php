<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ChatConversationResource;
use App\Filament\Resources\ChatConversationResource\Pages\ListChatConversations;
use App\Filament\Resources\ChatConversationResource\Pages\ViewChatConversation;
use App\Models\Admin;
use App\Models\ChatAiRequest;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatSummary;
use App\Models\Student;
use Filament\Facades\Filament;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Filament ChatConversation dashboard regression tests.
 *
 * Policy:
 *   viewAny / view / restore / delete → super_admin || support_admin
 *   create → false (always)
 *   update → false (always)
 *   deleteAny → false (no bulk hard delete)
 *
 * Summary feature (Part 1):
 *   - conversation.summary_updated_at is set by SummarizeChatConversation job.
 *   - chatSummary relationship links to chat_summaries via session_id.
 *   - Both the list table and the view infolist expose the summary + timestamp.
 */
class ChatConversationDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    // =========================================================================
    // A) Access control — who can see the chat resource
    // =========================================================================

    public function test_super_admin_can_list_conversations(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $this->actingAs($admin, 'web');

        Livewire::test(ListChatConversations::class)
            ->assertSuccessful();
    }

    public function test_support_admin_can_list_conversations(): void
    {
        $admin = Admin::factory()->supportAdmin()->create();
        $this->actingAs($admin, 'web');

        Livewire::test(ListChatConversations::class)
            ->assertSuccessful();
    }

    public function test_academic_admin_cannot_access_chat_list(): void
    {
        $admin = Admin::factory()->academicAdmin()->create();
        $this->actingAs($admin, 'web');

        Livewire::test(ListChatConversations::class)
            ->assertForbidden();
    }

    public function test_vehicle_admin_cannot_access_chat_list(): void
    {
        $admin = Admin::factory()->vehicleAdmin()->create();
        $this->actingAs($admin, 'web');

        Livewire::test(ListChatConversations::class)
            ->assertForbidden();
    }

    // =========================================================================
    // B) List — data and columns
    // =========================================================================

    public function test_list_shows_all_conversations_including_student_hidden(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $student = Student::factory()->create();

        ChatConversation::factory()->create(['student_id' => $student->id]);
        ChatConversation::factory()->create([
            'student_id' => $student->id,
            'deleted_by_student_at' => now(),
        ]);

        $this->actingAs($admin, 'web');

        Livewire::test(ListChatConversations::class)
            ->assertCountTableRecords(2);
    }

    public function test_list_shows_conversations_from_all_students(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $studentA = Student::factory()->create();
        $studentB = Student::factory()->create();

        ChatConversation::factory()->create(['student_id' => $studentA->id]);
        ChatConversation::factory()->create(['student_id' => $studentB->id]);

        $this->actingAs($admin, 'web');

        Livewire::test(ListChatConversations::class)
            ->assertCountTableRecords(2);
    }

    public function test_create_is_disabled(): void
    {
        $this->assertFalse(ChatConversationResource::canCreate());
    }

    // =========================================================================
    // C) Summary feature — list table
    // =========================================================================

    public function test_list_shows_summary_text_when_chat_summary_exists(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $student = Student::factory()->create();
        $conversation = ChatConversation::factory()->withSession()->create([
            'student_id' => $student->id,
            'summary_updated_at' => now(),
        ]);

        // The AI service writes directly to chat_summaries via session_id.
        ChatSummary::create([
            'session_id' => $conversation->session_id,
            'user_id' => $student->id,
            'summary_text' => 'The student asked about course registration.',
        ]);

        $this->actingAs($admin, 'web');

        // The summary column is toggleable/hidden by default — assert it can be
        // loaded via the relationship on the page without throwing.
        Livewire::test(ListChatConversations::class)
            ->assertSuccessful()
            ->assertCountTableRecords(1);

        // Verify the relationship resolves correctly via the model.
        $loaded = ChatConversation::with('chatSummary')->find($conversation->id);
        $this->assertSame(
            'The student asked about course registration.',
            $loaded->chatSummary?->summary_text,
        );
    }

    public function test_list_has_null_summary_when_no_chat_summary_row_exists(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $student = Student::factory()->create();
        $conversation = ChatConversation::factory()->create(['student_id' => $student->id]);

        $this->actingAs($admin, 'web');

        Livewire::test(ListChatConversations::class)->assertSuccessful();

        $loaded = ChatConversation::with('chatSummary')->find($conversation->id);
        $this->assertNull($loaded->chatSummary);
        $this->assertNull($loaded->summary_updated_at);
    }

    public function test_summary_updated_at_column_is_visible_by_default(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $this->actingAs($admin, 'web');

        $component = Livewire::test(ListChatConversations::class);

        $table = ChatConversationResource::table(
            Table::make($component->instance())
        );

        $column = collect($table->getColumns())->first(
            fn ($col) => $col->getName() === 'summary_updated_at'
        );

        $this->assertNotNull($column, 'summary_updated_at column must exist on the table');
        $this->assertFalse(
            $column->isToggledHiddenByDefault(),
            'summary_updated_at must be visible by default (isToggledHiddenByDefault must be false)'
        );
    }

    public function test_summary_text_column_is_toggleable_but_hidden_by_default(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $this->actingAs($admin, 'web');

        $component = Livewire::test(ListChatConversations::class);

        $table = ChatConversationResource::table(
            Table::make($component->instance())
        );

        $column = collect($table->getColumns())->first(
            fn ($col) => $col->getName() === 'chatSummary.summary_text'
        );

        $this->assertNotNull($column, 'chatSummary.summary_text column must exist on the table');
        $this->assertTrue(
            $column->isToggledHiddenByDefault(),
            'chatSummary.summary_text should remain hidden by default to keep the table readable'
        );
    }

    // =========================================================================
    // D) View page — single conversation
    // =========================================================================

    public function test_super_admin_can_view_conversation(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $student = Student::factory()->create();
        $conversation = ChatConversation::factory()->create(['student_id' => $student->id]);

        $this->actingAs($admin, 'web');

        Livewire::test(ViewChatConversation::class, ['record' => $conversation->getKey()])
            ->assertSuccessful();
    }

    public function test_view_page_shows_summary_section_with_text(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $student = Student::factory()->create();
        $conversation = ChatConversation::factory()->withSession()->create([
            'student_id' => $student->id,
            'summary_updated_at' => now()->subHour(),
        ]);

        ChatSummary::create([
            'session_id' => $conversation->session_id,
            'user_id' => $student->id,
            'summary_text' => 'Student inquired about academic calendar.',
        ]);

        $this->actingAs($admin, 'web');

        Livewire::test(ViewChatConversation::class, ['record' => $conversation->getKey()])
            ->assertSuccessful()
            ->assertSeeText('Student inquired about academic calendar.');
    }

    // =========================================================================
    // E) Restore action
    // =========================================================================

    public function test_super_admin_can_restore_hidden_conversation(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $student = Student::factory()->create();
        $conversation = ChatConversation::factory()->create([
            'student_id' => $student->id,
            'deleted_by_student_at' => now(),
        ]);

        $this->actingAs($admin, 'web');

        Livewire::test(ListChatConversations::class)
            ->callTableAction('restore', $conversation)
            ->assertHasNoTableActionErrors();

        $this->assertNull($conversation->fresh()->deleted_by_student_at);
    }

    public function test_restore_is_idempotent_when_already_visible(): void
    {
        // The restore table action is only visible for hidden conversations.
        // Test idempotency by calling the static method directly (not via Livewire action).
        $admin = Admin::factory()->superAdmin()->create();
        $student = Student::factory()->create();
        $conversation = ChatConversation::factory()->create(['student_id' => $student->id]);

        $this->actingAs($admin, 'web');

        // Calling restore on an already-visible conversation must not throw
        // and must not change the conversation state.
        ChatConversationResource::restoreConversation($conversation);

        $this->assertNull($conversation->fresh()->deleted_by_student_at);
    }

    public function test_support_admin_can_restore_hidden_conversation(): void
    {
        $admin = Admin::factory()->supportAdmin()->create();
        $student = Student::factory()->create();
        $conversation = ChatConversation::factory()->create([
            'student_id' => $student->id,
            'deleted_by_student_at' => now(),
        ]);

        $this->actingAs($admin, 'web');

        Livewire::test(ListChatConversations::class)
            ->callTableAction('restore', $conversation)
            ->assertHasNoTableActionErrors();

        $this->assertNull($conversation->fresh()->deleted_by_student_at);
    }

    // =========================================================================
    // F) Hard-delete action
    // =========================================================================

    public function test_super_admin_can_hard_delete_conversation(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $student = Student::factory()->create();
        $conversation = ChatConversation::factory()->create(['student_id' => $student->id]);

        $this->actingAs($admin, 'web');

        Livewire::test(ListChatConversations::class)
            ->callTableAction('hardDelete', $conversation)
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseMissing('chat_conversations', ['id' => $conversation->id]);
    }

    public function test_hard_delete_cascades_to_messages_and_ai_requests(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $student = Student::factory()->create();
        $conversation = ChatConversation::factory()->create(['student_id' => $student->id]);

        $userMsg = ChatMessage::factory()->create([
            'chat_conversation_id' => $conversation->id,
            'role' => ChatMessage::ROLE_USER,
            'sequence_number' => 1,
        ]);
        $assistantMsg = ChatMessage::factory()->pendingAssistant()->create([
            'chat_conversation_id' => $conversation->id,
            'sequence_number' => 2,
        ]);
        ChatAiRequest::factory()
            ->completed()
            ->forCycle($conversation, $userMsg, $assistantMsg)
            ->create();

        $this->actingAs($admin, 'web');

        Livewire::test(ListChatConversations::class)
            ->callTableAction('hardDelete', $conversation)
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseMissing('chat_conversations', ['id' => $conversation->id]);
        $this->assertDatabaseMissing('chat_messages', ['chat_conversation_id' => $conversation->id]);
        $this->assertDatabaseMissing('chat_ai_requests', ['chat_conversation_id' => $conversation->id]);
    }

    public function test_hard_delete_is_blocked_when_ai_request_is_in_flight(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $student = Student::factory()->create();
        $conversation = ChatConversation::factory()->create(['student_id' => $student->id]);

        $userMsg = ChatMessage::factory()->create([
            'chat_conversation_id' => $conversation->id,
            'role' => ChatMessage::ROLE_USER,
            'sequence_number' => 1,
        ]);
        $assistantMsg = ChatMessage::factory()->pendingAssistant()->create([
            'chat_conversation_id' => $conversation->id,
            'sequence_number' => 2,
        ]);
        ChatAiRequest::factory()
            ->queued()
            ->forCycle($conversation, $userMsg, $assistantMsg)
            ->create();

        $this->actingAs($admin, 'web');

        Livewire::test(ListChatConversations::class)
            ->callTableAction('hardDelete', $conversation);

        // Conversation must NOT have been deleted.
        $this->assertDatabaseHas('chat_conversations', ['id' => $conversation->id]);
    }

    public function test_no_bulk_delete_action_exists(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $this->actingAs($admin, 'web');

        // bulkActions([]) ensures no bulk hard delete.
        $component = Livewire::test(ListChatConversations::class);
        // There are zero bulk actions registered.
        $component->assertSuccessful();

        $actions = ChatConversationResource::table(
            Table::make($component->instance())
        )->getBulkActions();

        $this->assertEmpty($actions, 'No bulk actions should be registered on the chat conversation table');
    }
}
