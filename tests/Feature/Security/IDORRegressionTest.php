<?php

namespace Tests\Feature\Security;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Student;
use App\Models\VehicleRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * IDOR regression tests — pentest-style injection of ownership fields.
 *
 * These tests confirm that the server ALWAYS uses the authenticated student's
 * identity (from the Sanctum token) and never accepts ownership fields from
 * the request body, URL parameters, or other user-controlled input.
 */
class IDORRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsStudent(Student $student): void
    {
        Sanctum::actingAs($student, ['*']);
    }

    // =========================================================================
    // Vehicle request IDOR
    // =========================================================================

    public function test_vehicle_request_with_injected_student_id_belongs_to_authenticated_student(): void
    {
        $studentA = Student::factory()->create();
        $studentB = Student::factory()->create();

        $this->actingAsStudent($studentA);

        $this->postJson('/api/v1/student/vehicle-requests', [
            'vehicle_type' => 'Car',
            'vehicle_model' => 'Toyota Corolla',
            'vehicle_color' => 'White',
            'plate_number' => 'ABC-1234',
            // Injection attempts — these must be ignored by the server
            'student_id' => $studentB->id,
            'user_id' => $studentB->id,
            'owner_id' => $studentB->id,
            'id' => $studentB->id,
        ])->assertOk();

        // The created request must belong to Student A
        $this->assertDatabaseHas('vehicle_requests', [
            'student_id' => $studentA->id,
            'plate_number' => 'ABC-1234',
        ]);

        // Student B must not have this request in their history
        $this->actingAsStudent($studentB);
        $bHistory = $this->getJson('/api/v1/student/vehicle-requests/history')
            ->assertOk()
            ->json('data');

        $this->assertEmpty(
            collect($bHistory)->where('plate_number', 'ABC-1234'),
            'Injected student_id must not assign the request to another student'
        );
    }

    public function test_vehicle_history_only_returns_own_requests(): void
    {
        $studentA = Student::factory()->create();
        $studentB = Student::factory()->create();

        VehicleRequest::factory()->pending()->create([
            'student_id' => $studentA->id,
            'plate_number' => 'PLATE-A',
        ]);
        VehicleRequest::factory()->pending()->create([
            'student_id' => $studentB->id,
            'plate_number' => 'PLATE-B',
        ]);

        $this->actingAsStudent($studentA);

        $plates = collect(
            $this->getJson('/api/v1/student/vehicle-requests/history')
                ->assertOk()->json('data')
        )->pluck('plate_number');

        $this->assertTrue($plates->contains('PLATE-A'));
        $this->assertFalse($plates->contains('PLATE-B'));
    }

    // =========================================================================
    // Chat conversation IDOR
    // =========================================================================

    public function test_student_b_cannot_view_student_a_chat(): void
    {
        $studentA = Student::factory()->create();
        $studentB = Student::factory()->create();

        $chatA = ChatConversation::factory()->create(['student_id' => $studentA->id]);

        $this->actingAsStudent($studentB);

        // Attempt to access Student A's chat using Student B's token
        $this->getJson("/api/v1/student/chats/{$chatA->uuid}")
            ->assertNotFound();
    }

    public function test_student_b_cannot_rename_student_a_chat(): void
    {
        $studentA = Student::factory()->create();
        $studentB = Student::factory()->create();

        $chatA = ChatConversation::factory()->create(['student_id' => $studentA->id]);

        $this->actingAsStudent($studentB);

        $this->patchJson("/api/v1/student/chats/{$chatA->uuid}", [
            'title' => 'Injected Title',
        ])->assertNotFound();

        $this->assertDatabaseMissing('chat_conversations', [
            'id' => $chatA->id,
            'title' => 'Injected Title',
        ]);
    }

    public function test_student_b_cannot_delete_student_a_chat(): void
    {
        $studentA = Student::factory()->create();
        $studentB = Student::factory()->create();

        $chatA = ChatConversation::factory()->create(['student_id' => $studentA->id]);

        $this->actingAsStudent($studentB);

        $this->deleteJson("/api/v1/student/chats/{$chatA->uuid}")
            ->assertNotFound();

        // The conversation must still exist (not deleted)
        $this->assertDatabaseHas('chat_conversations', ['id' => $chatA->id]);
    }

    public function test_student_b_cannot_poll_student_a_message_status(): void
    {
        $studentA = Student::factory()->create();
        $studentB = Student::factory()->create();

        $chatA = ChatConversation::factory()->create(['student_id' => $studentA->id]);
        $msg = ChatMessage::factory()->create([
            'chat_conversation_id' => $chatA->id,
            'role' => ChatMessage::ROLE_ASSISTANT,
            'status' => ChatMessage::STATUS_PENDING,
            'uuid' => (string) Str::uuid(),
        ]);

        $this->actingAsStudent($studentB);

        $this->getJson("/api/v1/student/chats/{$chatA->uuid}/messages/{$msg->uuid}/status")
            ->assertNotFound();
    }

    public function test_chat_index_only_returns_own_conversations(): void
    {
        $studentA = Student::factory()->create();
        $studentB = Student::factory()->create();

        ChatConversation::factory()->create([
            'student_id' => $studentA->id,
            'title' => 'Chat of A',
        ]);
        ChatConversation::factory()->create([
            'student_id' => $studentB->id,
            'title' => 'Chat of B',
        ]);

        $this->actingAsStudent($studentA);

        $titles = collect(
            $this->getJson('/api/v1/student/chats')
                ->assertOk()->json('data.conversations')
        )->pluck('title');

        $this->assertTrue($titles->contains('Chat of A'));
        $this->assertFalse($titles->contains('Chat of B'));
    }

    public function test_chat_uuid_route_is_scoped_to_auth_student(): void
    {
        $studentA = Student::factory()->create();
        $studentB = Student::factory()->create();

        // Student B's chat UUID
        $chatB = ChatConversation::factory()->create(['student_id' => $studentB->id]);

        // Student A uses Student B's UUID — must get 404
        $this->actingAsStudent($studentA);

        $this->getJson("/api/v1/student/chats/{$chatB->uuid}")
            ->assertNotFound();
    }
}
