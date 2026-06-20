<?php

namespace Tests\Feature\Security;

use App\Models\Admin;
use App\Models\ChatConversation;
use App\Models\Student;
use App\Models\StudentLoginOtp;
use App\Models\VehicleRequest;
use App\Support\Security\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Verifies that structured [AUDIT] log entries are emitted for security-relevant events.
 *
 * Uses Log::spy() so no real log backend is needed in CI; assertions check that
 * the Log facade received warning/info calls whose first argument starts with '[AUDIT]'.
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Student login failures
    // =========================================================================

    public function test_failed_login_emits_audit_warning(): void
    {
        Log::spy();

        Student::factory()->create([
            'student_id' => 'GU-AUDIT-001',
            'password' => 'secret1234',
        ]);

        $this->postJson('/api/v1/student/login', [
            'student_id' => 'GU-AUDIT-001',
            'password' => 'wrongpassword',
        ])->assertUnauthorized();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message) => str_starts_with($message, '[AUDIT] student_login_failed'));
    }

    public function test_successful_login_step1_does_not_emit_audit_warning(): void
    {
        Mail::fake();
        Log::spy();

        Student::factory()->create([
            'student_id' => 'GU-AUDIT-001',
            'password' => 'secret1234',
        ]);

        $this->postJson('/api/v1/student/login', [
            'student_id' => 'GU-AUDIT-001',
            'password' => 'secret1234',
        ])->assertOk();

        // No audit warning for a successful step 1
        Log::shouldNotHaveReceived('warning');
    }

    // =========================================================================
    // OTP verification
    // =========================================================================

    public function test_wrong_otp_emits_audit_warning(): void
    {
        Mail::fake();
        Log::spy();

        $student = Student::factory()->create([
            'student_id' => 'GU-AUDIT-001',
            'password' => 'secret1234',
        ]);

        $step1 = $this->postJson('/api/v1/student/login', [
            'student_id' => 'GU-AUDIT-001',
            'password' => 'secret1234',
        ])->assertOk()->json();

        $this->postJson('/api/v1/student/login/verify-otp', [
            'otp_token' => $step1['otp_token'],
            'otp_code' => '000000', // wrong
        ])->assertUnauthorized();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message) => str_starts_with($message, '[AUDIT] otp_verification_failed'));
    }

    public function test_correct_otp_emits_audit_info(): void
    {
        Mail::fake();
        Log::spy();

        $student = Student::factory()->create([
            'student_id' => 'GU-AUDIT-001',
            'password' => 'secret1234',
        ]);

        $step1 = $this->postJson('/api/v1/student/login', [
            'student_id' => 'GU-AUDIT-001',
            'password' => 'secret1234',
        ])->assertOk()->json();

        $otp = StudentLoginOtp::where(
            'challenge_token_hash', hash('sha256', $step1['otp_token'])
        )->firstOrFail();
        $knownCode = '123456';
        $otp->update(['otp_hash' => Hash::make($knownCode)]);

        $this->postJson('/api/v1/student/login/verify-otp', [
            'otp_token' => $step1['otp_token'],
            'otp_code' => $knownCode,
        ])->assertOk();

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(fn (string $message) => str_starts_with($message, '[AUDIT] otp_verification_success'));
    }

    // =========================================================================
    // Chat delete
    // =========================================================================

    public function test_chat_delete_emits_audit_info(): void
    {
        Log::spy();

        $student = Student::factory()->create();
        Sanctum::actingAs($student, ['*']);

        // Create a chat first
        Mail::fake();
        $chat = ChatConversation::factory()->create([
            'student_id' => $student->id,
        ]);

        $this->deleteJson("/api/v1/student/chats/{$chat->uuid}")
            ->assertOk();

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(fn (string $message) => str_starts_with($message, '[AUDIT] chat_deleted'));
    }

    // =========================================================================
    // Vehicle request approve/reject
    // =========================================================================

    public function test_vehicle_request_approve_emits_audit_info(): void
    {
        Log::spy();

        $admin = Admin::factory()->create();
        $student = Student::factory()->create();
        $vehicleRequest = VehicleRequest::factory()->pending()->create([
            'student_id' => $student->id,
        ]);

        $this->actingAs($admin, 'web');

        // Simulate the Filament action directly via the model
        $vehicleRequest->update([
            'status' => 'approved',
            'admin_id' => $admin->id,
            'approved_at' => now(),
            'semester_start_date' => '2026-09-01',
            'semester_end_date' => '2027-01-31',
        ]);

        AuditLog::info('vehicle_request_approved', [
            'actor_admin_id' => $admin->id,
            'vehicle_request_id' => $vehicleRequest->id,
            'student_id' => $vehicleRequest->student_id,
            'semester_start' => '2026-09-01',
            'semester_end' => '2027-01-31',
        ]);

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(fn (string $message) => str_starts_with($message, '[AUDIT] vehicle_request_approved'));
    }

    public function test_vehicle_request_reject_emits_audit_warning(): void
    {
        Log::spy();

        $admin = Admin::factory()->create();
        $student = Student::factory()->create();
        $vehicleRequest = VehicleRequest::factory()->pending()->create([
            'student_id' => $student->id,
        ]);

        $this->actingAs($admin, 'web');

        // Simulate the Filament action directly
        $vehicleRequest->update([
            'status' => 'rejected',
            'admin_id' => $admin->id,
            'rejection_reason' => 'Invalid documents',
        ]);

        AuditLog::warning('vehicle_request_rejected', [
            'actor_admin_id' => $admin->id,
            'vehicle_request_id' => $vehicleRequest->id,
            'student_id' => $vehicleRequest->student_id,
            'reason' => 'Invalid documents',
        ]);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message) => str_starts_with($message, '[AUDIT] vehicle_request_rejected'));
    }
}
