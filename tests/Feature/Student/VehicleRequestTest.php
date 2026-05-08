<?php

namespace Tests\Feature\Student;

use App\Models\Student;
use App\Models\VehicleRequest;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleRequestTest extends TestCase
{
    use RefreshDatabase;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->student = Student::factory()->create();
    }

    public function test_vehicle_request_creation(): void
    {
        \Laravel\Sanctum\Sanctum::actingAs($this->student);
        $response = $this->postJson('/api/v1/student/vehicle-requests', [
                'vehicle_type' => 'Car',
                'vehicle_model' => 'Toyota Camry',
                'vehicle_color' => 'Silver',
                'plate_number' => 'ABC-1234',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Vehicle request submitted successfully',
            ]);

        $this->assertDatabaseHas('vehicle_requests', [
            'student_id' => $this->student->id,
            'status' => 'pending',
            'plate_number' => 'ABC-1234',
        ]);
    }

    public function test_pending_request_blocks_duplicate_request(): void
    {
        VehicleRequest::create([
            'student_id' => $this->student->id,
            'vehicle_type' => 'Car',
            'vehicle_model' => 'Honda Civic',
            'vehicle_color' => 'Black',
            'plate_number' => 'XYZ-987',
            'status' => 'pending',
        ]);

        \Laravel\Sanctum\Sanctum::actingAs($this->student);
        $response = $this->postJson('/api/v1/student/vehicle-requests', [
                'vehicle_type' => 'Car',
                'vehicle_model' => 'Toyota Camry',
                'vehicle_color' => 'Silver',
                'plate_number' => 'ABC-1234',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'You already have a pending vehicle request or active permit.',
            ]);
    }

    public function test_approved_non_expired_permit_blocks_new_request(): void
    {
        VehicleRequest::create([
            'student_id' => $this->student->id,
            'vehicle_type' => 'Car',
            'vehicle_model' => 'Honda Civic',
            'vehicle_color' => 'Black',
            'plate_number' => 'XYZ-987',
            'status' => 'approved',
            'semester_start_date' => Carbon::now()->addDays(5),
            'semester_end_date' => Carbon::now()->addMonths(3), // Future-ending
        ]);

        \Laravel\Sanctum\Sanctum::actingAs($this->student);
        $response = $this->postJson('/api/v1/student/vehicle-requests', [
                'vehicle_type' => 'Car',
                'vehicle_model' => 'Toyota Camry',
                'vehicle_color' => 'Silver',
                'plate_number' => 'ABC-1234',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'You already have a pending vehicle request or active permit.',
            ]);
    }

    public function test_expired_approved_permit_allows_new_request(): void
    {
        VehicleRequest::create([
            'student_id' => $this->student->id,
            'vehicle_type' => 'Car',
            'vehicle_model' => 'Honda Civic',
            'vehicle_color' => 'Black',
            'plate_number' => 'XYZ-987',
            'status' => 'approved',
            'semester_start_date' => Carbon::now()->subMonths(6),
            'semester_end_date' => Carbon::now()->subDays(1), // Expired
        ]);

        \Laravel\Sanctum\Sanctum::actingAs($this->student);
        $response = $this->postJson('/api/v1/student/vehicle-requests', [
                'vehicle_type' => 'Car',
                'vehicle_model' => 'Toyota Camry',
                'vehicle_color' => 'Silver',
                'plate_number' => 'ABC-1234',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }

    public function test_rejected_request_allows_new_request(): void
    {
        VehicleRequest::create([
            'student_id' => $this->student->id,
            'vehicle_type' => 'Car',
            'vehicle_model' => 'Honda Civic',
            'vehicle_color' => 'Black',
            'plate_number' => 'XYZ-987',
            'status' => 'rejected',
            'rejection_reason' => 'Invalid plate',
        ]);

        \Laravel\Sanctum\Sanctum::actingAs($this->student);
        $response = $this->postJson('/api/v1/student/vehicle-requests', [
                'vehicle_type' => 'Car',
                'vehicle_model' => 'Toyota Camry',
                'vehicle_color' => 'Silver',
                'plate_number' => 'ABC-1234',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }
}
