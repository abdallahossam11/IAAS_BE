<?php

namespace Tests\Feature\Api\Student;

use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression tests for the Student Profile API.
 *
 * Endpoint: GET /api/v1/student/profile (auth:sanctum + ensure.student)
 *
 * Returns: success, data.{full_name, student_id, email, gpa (float),
 * credits_completed, credits_required, faculty.{id, name}}.
 * Password is never returned.
 *
 * faculty_id is NOT NULL in the schema and required in the Filament form, so
 * $student->faculty is always a Faculty model; the controller's direct
 * $student->faculty->id access is safe in all production paths.
 *
 * EnsureIsStudent middleware blocks non-Student Sanctum tokens with 403.
 * Admin has no HasApiTokens, so an admin Sanctum token cannot be fabricated
 * in tests — this constraint is documented, not separately tested here.
 */
class StudentProfileApiTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // A) Happy path
    // =========================================================================

    public function test_authenticated_student_can_view_profile(): void
    {
        Sanctum::actingAs(Student::factory()->create(), ['*']);

        $this->getJson('/api/v1/student/profile')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_profile_returns_expected_scalar_fields(): void
    {
        $student = Student::factory()->create([
            'full_name' => 'Ahmed Hassan',
            'student_id' => 'GU-20240001',
            'email' => 'ahmed@example.com',
            'gpa' => 3.50,
            'credits_completed' => 60,
            'credits_required' => 140,
        ]);
        Sanctum::actingAs($student, ['*']);

        $data = $this->getJson('/api/v1/student/profile')->assertOk()->json('data');

        $this->assertSame('Ahmed Hassan', $data['full_name']);
        $this->assertSame('GU-20240001', $data['student_id']);
        $this->assertSame('ahmed@example.com', $data['email']);
        $this->assertSame(3.50, $data['gpa']);
        $this->assertSame(60, $data['credits_completed']);
        $this->assertSame(140, $data['credits_required']);
    }

    public function test_profile_includes_faculty_data(): void
    {
        $student = Student::factory()->create();
        Sanctum::actingAs($student, ['*']);

        $data = $this->getJson('/api/v1/student/profile')->assertOk()->json('data');

        $this->assertArrayHasKey('faculty', $data);
        $this->assertArrayHasKey('id', $data['faculty']);
        $this->assertArrayHasKey('name', $data['faculty']);
        $this->assertSame($student->faculty_id, $data['faculty']['id']);
    }

    public function test_profile_includes_faculty_sector_field_and_credit_hours(): void
    {
        $faculty = \App\Models\Faculty::factory()->create([
            'sector' => 'Sciences Sector',
            'field' => 'Computer Science',
            'credit_hours' => 127,
        ]);
        $student = Student::factory()->create(['faculty_id' => $faculty->id]);
        Sanctum::actingAs($student, ['*']);

        $data = $this->getJson('/api/v1/student/profile')->assertOk()->json('data');

        $this->assertSame('Sciences Sector', $data['faculty']['sector']);
        $this->assertSame('Computer Science', $data['faculty']['field']);
        $this->assertSame(127, $data['faculty']['credit_hours']);
    }

    public function test_profile_keeps_credits_required_as_student_snapshot(): void
    {
        // The student snapshot is independent of the live faculty credit_hours.
        $faculty = \App\Models\Faculty::factory()->create(['credit_hours' => 200]);
        $student = Student::factory()->create([
            'faculty_id' => $faculty->id,
            'credits_required' => 140,
        ]);
        Sanctum::actingAs($student, ['*']);

        $data = $this->getJson('/api/v1/student/profile')->assertOk()->json('data');

        $this->assertSame(140, $data['credits_required']);
        $this->assertSame(200, $data['faculty']['credit_hours']);
    }

    public function test_profile_does_not_expose_password(): void
    {
        Sanctum::actingAs(Student::factory()->create(), ['*']);

        $response = $this->getJson('/api/v1/student/profile')->assertOk();

        $this->assertArrayNotHasKey('password', $response->json('data'));
    }

    // =========================================================================
    // C) Date of birth
    // =========================================================================

    public function test_factory_can_create_student_with_date_of_birth(): void
    {
        $student = Student::factory()->create(['date_of_birth' => '2000-05-15']);

        $this->assertSame('2000-05-15', $student->fresh()->date_of_birth->format('Y-m-d'));
    }

    public function test_profile_returns_date_of_birth_in_iso_format(): void
    {
        $student = Student::factory()->create(['date_of_birth' => '2000-05-15']);
        Sanctum::actingAs($student, ['*']);

        $this->getJson('/api/v1/student/profile')
            ->assertOk()
            ->assertJsonPath('data.date_of_birth', '2000-05-15');
    }

    public function test_profile_returns_null_when_date_of_birth_missing(): void
    {
        $student = Student::factory()->create(['date_of_birth' => null]);
        Sanctum::actingAs($student, ['*']);

        $this->getJson('/api/v1/student/profile')
            ->assertOk()
            ->assertJsonPath('data.date_of_birth', null);
    }

    // =========================================================================
    // B) Auth guard
    // =========================================================================

    public function test_unauthenticated_profile_request_is_rejected(): void
    {
        $this->getJson('/api/v1/student/profile')->assertUnauthorized();
    }
}
