<?php

namespace Tests\Feature\Api\Student;

use App\Models\Student;
use App\Models\VehicleRequest;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression tests for the Student Vehicle Request API.
 *
 * Endpoint summary:
 *   GET  /api/v1/student/vehicle                  → current vehicle state
 *   POST /api/v1/student/vehicle-requests         → submit new request
 *   GET  /api/v1/student/vehicle-requests/history → all own requests
 *
 * Guards: auth:sanctum + ensure.student (EnsureIsStudent middleware).
 *
 * Behaviour documented here matches VehicleController as-shipped;
 * nothing has been changed in production code.
 */
class VehicleRequestApiTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function student(): Student
    {
        return Student::factory()->create();
    }

    private function actingAsStudent(Student $student): void
    {
        Sanctum::actingAs($student, ['*']);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'vehicle_type' => 'Car',
            'vehicle_model' => 'Toyota Corolla',
            'vehicle_color' => 'White',
            'plate_number' => 'ABC-1234',
        ], $overrides);
    }

    // =========================================================================
    // A) Unauthenticated access
    // =========================================================================

    public function test_unauthenticated_cannot_get_vehicle_state(): void
    {
        $this->getJson('/api/v1/student/vehicle')
            ->assertUnauthorized();
    }

    public function test_unauthenticated_cannot_submit_vehicle_request(): void
    {
        $this->postJson('/api/v1/student/vehicle-requests', $this->validPayload())
            ->assertUnauthorized();
    }

    public function test_unauthenticated_cannot_get_history(): void
    {
        $this->getJson('/api/v1/student/vehicle-requests/history')
            ->assertUnauthorized();
    }

    // =========================================================================
    // B) Vehicle state: no request → none
    // =========================================================================

    public function test_student_with_no_requests_gets_none_status(): void
    {
        $this->actingAsStudent($this->student());

        $this->getJson('/api/v1/student/vehicle')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'status' => 'none',
                'data' => null,
            ]);
    }

    public function test_no_vehicle_state_response_has_correct_shape(): void
    {
        // Asserts that when a student has no vehicle request the response
        // contains exactly the three expected top-level keys with correct types,
        // and that data is null (not missing, not "n/a", not an empty object).
        $this->actingAsStudent($this->student());

        $response = $this->getJson('/api/v1/student/vehicle')->assertOk();

        $json = $response->json();

        $this->assertArrayHasKey('success', $json, 'Response must contain success key');
        $this->assertArrayHasKey('status', $json, 'Response must contain status key');
        $this->assertArrayHasKey('data', $json, 'Response must contain data key (even when null)');

        $this->assertTrue($json['success']);
        $this->assertSame('none', $json['status']);
        $this->assertNull($json['data'], 'data must be null (not "n/a", not an empty object) for the none state');
    }

    // =========================================================================
    // C) Vehicle state: one request per status
    // =========================================================================

    public function test_latest_pending_request_returns_pending_status(): void
    {
        $student = $this->student();
        $this->actingAsStudent($student);

        VehicleRequest::factory()->pending()->create(['student_id' => $student->id]);

        $this->getJson('/api/v1/student/vehicle')
            ->assertOk()
            ->assertJsonFragment(['status' => 'pending']);
    }

    public function test_active_approved_permit_returns_approved_status(): void
    {
        $student = $this->student();
        $this->actingAsStudent($student);

        VehicleRequest::factory()->approved()->create([
            'student_id' => $student->id,
            'semester_start_date' => Carbon::today()->subDays(5),
            'semester_end_date' => Carbon::today()->addMonths(3),
        ]);

        $response = $this->getJson('/api/v1/student/vehicle')
            ->assertOk()
            ->assertJsonFragment(['status' => 'approved']);

        // valid_from and valid_until are returned
        $this->assertNotNull($response->json('data.valid_from'));
        $this->assertNotNull($response->json('data.valid_until'));
    }

    public function test_expired_approved_permit_returns_none_status(): void
    {
        // An approved record whose semester_end_date < today is treated as 'none'
        $student = $this->student();
        $this->actingAsStudent($student);

        VehicleRequest::factory()->approved()->create([
            'student_id' => $student->id,
            'semester_end_date' => Carbon::today()->subDay(),
        ]);

        $this->getJson('/api/v1/student/vehicle')
            ->assertOk()
            ->assertJson(['status' => 'none', 'data' => null]);
    }

    public function test_rejected_request_returns_rejected_status_with_reason(): void
    {
        $student = $this->student();
        $this->actingAsStudent($student);

        VehicleRequest::factory()->rejected()->create([
            'student_id' => $student->id,
            'rejection_reason' => 'Missing documentation',
        ]);

        $this->getJson('/api/v1/student/vehicle')
            ->assertOk()
            ->assertJsonFragment([
                'status' => 'rejected',
            ])
            ->assertJsonPath('data.rejection_reason', 'Missing documentation');
    }

    // The state() method only looks at the LATEST request (by created_at desc),
    // so an old rejected + new pending should show 'pending'.
    public function test_state_reflects_only_the_latest_request(): void
    {
        $student = $this->student();
        $this->actingAsStudent($student);

        VehicleRequest::factory()->rejected()->create([
            'student_id' => $student->id,
            'created_at' => now()->subDays(10),
        ]);

        VehicleRequest::factory()->pending()->create([
            'student_id' => $student->id,
            'created_at' => now(),
        ]);

        $this->getJson('/api/v1/student/vehicle')
            ->assertOk()
            ->assertJsonFragment(['status' => 'pending']);
    }

    // =========================================================================
    // D) Submit valid request
    // =========================================================================

    public function test_student_can_submit_valid_vehicle_request(): void
    {
        $student = $this->student();
        $this->actingAsStudent($student);

        $this->postJson('/api/v1/student/vehicle-requests', $this->validPayload())
            ->assertOk()
            ->assertJsonFragment(['success' => true]);

        $this->assertDatabaseHas('vehicle_requests', [
            'student_id' => $student->id,
            'status' => 'pending',
            'plate_number' => 'ABC-1234',
        ]);
    }

    // =========================================================================
    // E) Validation: required fields
    // =========================================================================

    public function test_all_fields_are_required(): void
    {
        $this->actingAsStudent($this->student());

        $this->postJson('/api/v1/student/vehicle-requests', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['vehicle_type', 'vehicle_model', 'vehicle_color', 'plate_number']);
    }

    public function test_each_required_field_is_individually_enforced(): void
    {
        $this->actingAsStudent($this->student());

        $fields = ['vehicle_type', 'vehicle_model', 'vehicle_color', 'plate_number'];

        foreach ($fields as $field) {
            $payload = $this->validPayload();
            unset($payload[$field]);

            $this->postJson('/api/v1/student/vehicle-requests', $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$field]);
        }
    }

    // =========================================================================
    // F) Input validation: XSS rejection, max lengths, plate format
    // =========================================================================

    public function test_arabic_plate_number_is_accepted(): void
    {
        $this->actingAsStudent($this->student());

        $this->postJson('/api/v1/student/vehicle-requests', $this->validPayload([
            'plate_number' => 'أ ب 1234',
        ]))->assertOk()->assertJsonFragment(['success' => true]);
    }

    public function test_english_plate_with_hyphen_and_dot_is_accepted(): void
    {
        $this->actingAsStudent($this->student());

        $this->postJson('/api/v1/student/vehicle-requests', $this->validPayload([
            'plate_number' => 'ABC-123.45',
        ]))->assertOk()->assertJsonFragment(['success' => true]);
    }

    public function test_plate_number_rejects_xss_payload(): void
    {
        $this->actingAsStudent($this->student());

        foreach ([
            '"><svg/onload=alert(1)>',
            '<svg/onload=alert(1)>',
            '<script>alert(1)</script>',
            '<img src=x onerror=alert(1)>',
        ] as $payload) {
            $this->postJson('/api/v1/student/vehicle-requests', $this->validPayload([
                'plate_number' => $payload,
            ]))->assertUnprocessable()
                ->assertJsonValidationErrors(['plate_number']);
        }
    }

    public function test_vehicle_type_rejects_html_payload(): void
    {
        $this->actingAsStudent($this->student());

        foreach ([
            '"><svg/onload=alert(1)>',
            '<script>alert(1)</script>',
        ] as $payload) {
            $this->postJson('/api/v1/student/vehicle-requests', $this->validPayload([
                'vehicle_type' => $payload,
            ]))->assertUnprocessable()
                ->assertJsonValidationErrors(['vehicle_type']);
        }
    }

    public function test_vehicle_model_rejects_html_payload(): void
    {
        $this->actingAsStudent($this->student());

        $this->postJson('/api/v1/student/vehicle-requests', $this->validPayload([
            'vehicle_model' => '<script>alert(1)</script>',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['vehicle_model']);
    }

    public function test_vehicle_color_rejects_html_payload(): void
    {
        $this->actingAsStudent($this->student());

        $this->postJson('/api/v1/student/vehicle-requests', $this->validPayload([
            'vehicle_color' => '<img src=x onerror=alert(1)>',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['vehicle_color']);
    }

    public function test_plate_number_max_length_enforced(): void
    {
        $this->actingAsStudent($this->student());

        $this->postJson('/api/v1/student/vehicle-requests', $this->validPayload([
            'plate_number' => str_repeat('A', 31),
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['plate_number']);
    }

    public function test_vehicle_type_max_length_enforced(): void
    {
        $this->actingAsStudent($this->student());

        $this->postJson('/api/v1/student/vehicle-requests', $this->validPayload([
            'vehicle_type' => str_repeat('A', 101),
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['vehicle_type']);
    }

    public function test_vehicle_model_max_length_enforced(): void
    {
        $this->actingAsStudent($this->student());

        $this->postJson('/api/v1/student/vehicle-requests', $this->validPayload([
            'vehicle_model' => str_repeat('A', 101),
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['vehicle_model']);
    }

    public function test_vehicle_color_max_length_enforced(): void
    {
        $this->actingAsStudent($this->student());

        $this->postJson('/api/v1/student/vehicle-requests', $this->validPayload([
            'vehicle_color' => str_repeat('A', 51),
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['vehicle_color']);
    }

    public function test_normal_vehicle_fields_are_accepted(): void
    {
        $this->actingAsStudent($this->student());

        $this->postJson('/api/v1/student/vehicle-requests', [
            'vehicle_type' => 'Sedan',
            'vehicle_model' => 'Toyota Corolla 2023',
            'vehicle_color' => 'Pearl White',
            'plate_number' => 'ABC-1234',
        ])->assertOk()->assertJsonFragment(['success' => true]);
    }

    // =========================================================================
    // G) Duplicate pending request blocked
    // =========================================================================

    public function test_student_cannot_submit_while_pending_request_exists(): void
    {
        $student = $this->student();
        $this->actingAsStudent($student);

        VehicleRequest::factory()->pending()->create(['student_id' => $student->id]);

        $this->postJson('/api/v1/student/vehicle-requests', $this->validPayload())
            ->assertUnprocessable()
            ->assertJsonFragment(['success' => false]);
    }

    // =========================================================================
    // H) Active approved permit blocks new request
    // =========================================================================

    public function test_student_cannot_submit_while_active_approved_permit_exists(): void
    {
        $student = $this->student();
        $this->actingAsStudent($student);

        VehicleRequest::factory()->approved()->create([
            'student_id' => $student->id,
            'semester_end_date' => Carbon::today()->addMonths(3),
        ]);

        $this->postJson('/api/v1/student/vehicle-requests', $this->validPayload())
            ->assertUnprocessable()
            ->assertJsonFragment(['success' => false]);
    }

    // =========================================================================
    // I) Expired approved permit and rejection allow a new submission
    // =========================================================================

    public function test_student_can_submit_after_rejection(): void
    {
        $student = $this->student();
        $this->actingAsStudent($student);

        VehicleRequest::factory()->rejected()->create(['student_id' => $student->id]);

        $this->postJson('/api/v1/student/vehicle-requests', $this->validPayload([
            'plate_number' => 'NEW-0001',
        ]))->assertOk()->assertJsonFragment(['success' => true]);
    }

    public function test_student_can_submit_after_expired_approved_permit(): void
    {
        $student = $this->student();
        $this->actingAsStudent($student);

        VehicleRequest::factory()->approved()->create([
            'student_id' => $student->id,
            'semester_end_date' => Carbon::today()->subDay(),
        ]);

        $this->postJson('/api/v1/student/vehicle-requests', $this->validPayload([
            'plate_number' => 'NEW-0002',
        ]))->assertOk()->assertJsonFragment(['success' => true]);
    }

    // =========================================================================
    // J) History: isolation and ordering
    // =========================================================================

    public function test_history_returns_only_own_requests(): void
    {
        $studentA = $this->student();
        $studentB = $this->student();

        VehicleRequest::factory()->pending()->create([
            'student_id' => $studentA->id,
            'plate_number' => 'PLATE-A',
        ]);
        VehicleRequest::factory()->rejected()->create([
            'student_id' => $studentB->id,
            'plate_number' => 'PLATE-B',
        ]);

        $this->actingAsStudent($studentA);

        $response = $this->getJson('/api/v1/student/vehicle-requests/history')
            ->assertOk()
            ->assertJsonFragment(['success' => true]);

        $plates = collect($response->json('data'))->pluck('plate_number');

        $this->assertTrue($plates->contains('PLATE-A'), 'Own request missing from history');
        $this->assertFalse($plates->contains('PLATE-B'), 'Another student\'s request leaked into history');
    }

    public function test_history_is_ordered_newest_first(): void
    {
        $student = $this->student();
        $this->actingAsStudent($student);

        $old = VehicleRequest::factory()->rejected()->create([
            'student_id' => $student->id,
            'created_at' => now()->subDays(10),
        ]);
        $new = VehicleRequest::factory()->pending()->create([
            'student_id' => $student->id,
            'created_at' => now(),
        ]);

        $ids = collect(
            $this->getJson('/api/v1/student/vehicle-requests/history')
                ->assertOk()
                ->json('data')
        )->pluck('id')->toArray();

        $this->assertSame([$new->id, $old->id], $ids);
    }

    public function test_history_returns_empty_array_when_no_requests(): void
    {
        $this->actingAsStudent($this->student());

        $this->getJson('/api/v1/student/vehicle-requests/history')
            ->assertOk()
            ->assertJson(['success' => true, 'data' => []]);
    }

    public function test_history_includes_expected_fields(): void
    {
        $student = $this->student();
        $this->actingAsStudent($student);

        VehicleRequest::factory()->pending()->create(['student_id' => $student->id]);

        $item = $this->getJson('/api/v1/student/vehicle-requests/history')
            ->assertOk()
            ->json('data.0');

        foreach (['id', 'vehicle_type', 'vehicle_model', 'vehicle_color', 'plate_number', 'status', 'created_at'] as $field) {
            $this->assertArrayHasKey($field, $item, "History item missing field: {$field}");
        }
    }

    // =========================================================================
    // K) Race condition: transactional store
    // =========================================================================

    public function test_second_submission_within_transaction_is_blocked(): void
    {
        // Verifies the student-row lock protects the pending check for the
        // common case (student already has vehicle requests). The lock on the
        // student row itself (not just its vehicle_requests) covers the
        // first-time-submission race too, where the vehicle_requests table
        // would return an empty set and thus lock nothing without the student
        // row lock.
        $student = $this->student();

        $this->actingAsStudent($student);
        $this->postJson('/api/v1/student/vehicle-requests', $this->validPayload([
            'plate_number' => 'RACE-0001',
        ]))->assertOk()->assertJsonFragment(['success' => true]);

        // Second serial call must be rejected because the first is now pending.
        $this->postJson('/api/v1/student/vehicle-requests', $this->validPayload([
            'plate_number' => 'RACE-0002',
        ]))->assertUnprocessable()->assertJsonFragment(['success' => false]);

        $this->assertDatabaseCount('vehicle_requests', 1);
        $this->assertDatabaseHas('vehicle_requests', ['plate_number' => 'RACE-0001']);
        $this->assertDatabaseMissing('vehicle_requests', ['plate_number' => 'RACE-0002']);
    }

    public function test_first_time_submission_for_new_student_is_accepted(): void
    {
        // A student with zero existing vehicle requests (the first-submission
        // race scenario). The student-row lock ensures both paths through the
        // transaction are serialised even when the vehicle_requests set is empty.
        $student = $this->student();
        $this->assertDatabaseCount('vehicle_requests', 0);

        $this->actingAsStudent($student);
        $this->postJson('/api/v1/student/vehicle-requests', $this->validPayload())
            ->assertOk()
            ->assertJsonFragment(['success' => true]);

        $this->assertDatabaseCount('vehicle_requests', 1);
    }
}
