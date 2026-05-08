<?php

namespace Tests\Feature\Gate;

use App\Models\Faculty;
use App\Models\Student;
use App\Models\VehicleRequest;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class VehicleAccessTest extends TestCase
{
    use RefreshDatabase;

    private Student $student;
    private string $apiKey = 'test_gate_api_key_123';

    protected function setUp(): void
    {
        parent::setUp();
        
        Config::set('services.gate.api_key', $this->apiKey);
        
        $this->student = Student::factory()->create();
    }

    public function test_missing_api_key_returns_401(): void
    {
        $response = $this->postJson('/api/v1/gate/vehicle-access/check', [
            'OCR' => 'ABC1234'
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthorized gate device.'
            ]);
    }

    public function test_wrong_api_key_returns_401(): void
    {
        $response = $this->postJson('/api/v1/gate/vehicle-access/check', [
            'OCR' => 'ABC1234'
        ], [
            'X-GATE-API-KEY' => 'wrong_key'
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthorized gate device.'
            ]);
    }

    public function test_approved_valid_permit_returns_allowed(): void
    {
        VehicleRequest::create([
            'student_id' => $this->student->id,
            'vehicle_type' => 'Car',
            'vehicle_model' => 'Honda',
            'vehicle_color' => 'Black',
            'plate_number' => 'ABC 1234',
            'status' => 'approved',
            'semester_start_date' => Carbon::now()->subDays(5),
            'semester_end_date' => Carbon::now()->addMonths(3),
        ]);

        $response = $this->postJson('/api/v1/gate/vehicle-access/check', [
            'OCR' => 'ABC-1234'
        ], [
            'X-GATE-API-KEY' => $this->apiKey
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'access' => 'allowed',
                'data' => [
                    'normalized_plate' => 'abc1234'
                ]
            ]);
    }

    public function test_pending_request_returns_denied(): void
    {
        VehicleRequest::create([
            'student_id' => $this->student->id,
            'vehicle_type' => 'Car',
            'vehicle_model' => 'Honda',
            'vehicle_color' => 'Black',
            'plate_number' => 'ABC 1234',
            'status' => 'pending',
        ]);

        $response = $this->postJson('/api/v1/gate/vehicle-access/check', [
            'OCR' => 'ABC-1234'
        ], [
            'X-GATE-API-KEY' => $this->apiKey
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'access' => 'denied',
            ]);
    }

    public function test_rejected_request_returns_denied(): void
    {
        VehicleRequest::create([
            'student_id' => $this->student->id,
            'vehicle_type' => 'Car',
            'vehicle_model' => 'Honda',
            'vehicle_color' => 'Black',
            'plate_number' => 'ABC 1234',
            'status' => 'rejected',
            'rejection_reason' => 'Bad image',
        ]);

        $response = $this->postJson('/api/v1/gate/vehicle-access/check', [
            'OCR' => 'ABC-1234'
        ], [
            'X-GATE-API-KEY' => $this->apiKey
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'access' => 'denied',
            ]);
    }

    public function test_expired_approved_request_returns_denied(): void
    {
        VehicleRequest::create([
            'student_id' => $this->student->id,
            'vehicle_type' => 'Car',
            'vehicle_model' => 'Honda',
            'vehicle_color' => 'Black',
            'plate_number' => 'ABC 1234',
            'status' => 'approved',
            'semester_start_date' => Carbon::now()->subMonths(6),
            'semester_end_date' => Carbon::now()->subDays(1),
        ]);

        $response = $this->postJson('/api/v1/gate/vehicle-access/check', [
            'OCR' => 'ABC-1234'
        ], [
            'X-GATE-API-KEY' => $this->apiKey
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'access' => 'denied',
            ]);
    }

    public function test_arabic_ocr_digits_normalize_correctly(): void
    {
        VehicleRequest::create([
            'student_id' => $this->student->id,
            'vehicle_type' => 'Car',
            'vehicle_model' => 'Honda',
            'vehicle_color' => 'Black',
            'plate_number' => 'أ إ آ ى ة 123',
            'status' => 'approved',
            'semester_start_date' => Carbon::now()->subDays(5),
            'semester_end_date' => Carbon::now()->addMonths(3),
        ]);

        // OCR sends Arabic characters and Hindi numerals
        $response = $this->postJson('/api/v1/gate/vehicle-access/check', [
            'OCR' => 'ا ا ا ي ه ١٢٣' // Testing normalization
        ], [
            'X-GATE-API-KEY' => $this->apiKey
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'access' => 'allowed',
                'data' => [
                    'normalized_plate' => 'ااايه123'
                ]
            ]);
    }
}
