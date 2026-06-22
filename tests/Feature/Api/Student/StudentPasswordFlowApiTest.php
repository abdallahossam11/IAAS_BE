<?php

namespace Tests\Feature\Api\Student;

use App\Mail\StudentPasswordResetOtpMail;
use App\Models\Student;
use App\Models\StudentLoginOtp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests for the two student password flows:
 *
 *   Task 1 — First-login / temporary-password enforcement
 *     - OTP verify + profile expose must_change_password
 *     - business endpoints return 409 PASSWORD_CHANGE_REQUIRED until changed
 *     - change-password clears the flag and unblocks the endpoints
 *
 *   Task 2 — Forgot-password via emailed OTP
 *     - POST /api/v1/student/forgot-password
 *     - POST /api/v1/student/forgot-password/reset
 *
 * Cross-flow isolation: login OTPs and password-reset OTPs are separated by the
 * `purpose` column and cannot be used interchangeably.
 */
class StudentPasswordFlowApiTest extends TestCase
{
    use RefreshDatabase;

    private const STRONG_PASSWORD = 'NewSecurePa55!';

    /**
     * Complete the two-step login and return the issued Sanctum token.
     */
    private function loginAndVerify(Student $student, string $password): string
    {
        Mail::fake();

        $step1 = $this->postJson('/api/v1/student/login', [
            'student_id' => $student->student_id,
            'password' => $password,
        ])->assertOk()->json();

        $otp = StudentLoginOtp::where(
            'challenge_token_hash', hash('sha256', $step1['otp_token'])
        )->firstOrFail();
        $otp->update(['otp_hash' => Hash::make('123456')]);

        return $this->postJson('/api/v1/student/login/verify-otp', [
            'otp_token' => $step1['otp_token'],
            'otp_code' => '123456',
        ])->assertOk()->json('data.token');
    }

    // =========================================================================
    // Task 1 — First-login enforcement
    // =========================================================================

    public function test_admin_created_student_has_must_change_password_flag(): void
    {
        // Models the Filament/admin creation path via the factory state.
        $student = Student::factory()->mustChangePassword()->create();

        $this->assertTrue($student->fresh()->password_must_be_changed);
        $this->assertNull($student->fresh()->password_changed_at);
    }

    public function test_otp_verify_returns_must_change_password_true_when_required(): void
    {
        $student = Student::factory()->mustChangePassword()->create([
            'student_id' => 'GU-20240001',
            'password' => 'password123',
        ]);

        Mail::fake();

        $step1 = $this->postJson('/api/v1/student/login', [
            'student_id' => 'GU-20240001',
            'password' => 'password123',
        ])->assertOk()->json();

        $otp = StudentLoginOtp::where(
            'challenge_token_hash', hash('sha256', $step1['otp_token'])
        )->firstOrFail();
        $otp->update(['otp_hash' => Hash::make('123456')]);

        $this->postJson('/api/v1/student/login/verify-otp', [
            'otp_token' => $step1['otp_token'],
            'otp_code' => '123456',
        ])->assertOk()
            ->assertJsonPath('data.must_change_password', true)
            ->assertJsonPath('data.student.must_change_password', true);
    }

    public function test_otp_verify_returns_must_change_password_false_for_normal_student(): void
    {
        $student = Student::factory()->create([
            'student_id' => 'GU-20240002',
            'password' => 'password123',
        ]);

        $this->loginAndVerify($student, 'password123');

        // Re-run to assert the JSON payload explicitly.
        Mail::fake();
        $step1 = $this->postJson('/api/v1/student/login', [
            'student_id' => 'GU-20240002',
            'password' => 'password123',
        ])->assertOk()->json();
        $otp = StudentLoginOtp::where(
            'challenge_token_hash', hash('sha256', $step1['otp_token'])
        )->firstOrFail();
        $otp->update(['otp_hash' => Hash::make('123456')]);

        $this->postJson('/api/v1/student/login/verify-otp', [
            'otp_token' => $step1['otp_token'],
            'otp_code' => '123456',
        ])->assertOk()->assertJsonPath('data.must_change_password', false);
    }

    public function test_vehicle_endpoint_blocked_with_409_when_password_change_required(): void
    {
        Sanctum::actingAs(Student::factory()->mustChangePassword()->create(), ['*']);

        $this->getJson('/api/v1/student/vehicle')
            ->assertStatus(409)
            ->assertJson([
                'message' => 'Password change is required before continuing.',
                'code' => 'PASSWORD_CHANGE_REQUIRED',
                'must_change_password' => true,
            ]);
    }

    public function test_chat_endpoint_blocked_with_409_when_password_change_required(): void
    {
        Sanctum::actingAs(Student::factory()->mustChangePassword()->create(), ['*']);

        $this->getJson('/api/v1/student/chats')
            ->assertStatus(409)
            ->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
    }

    public function test_profile_and_logout_are_not_blocked_when_change_required(): void
    {
        Sanctum::actingAs(Student::factory()->mustChangePassword()->create(), ['*']);

        $this->getJson('/api/v1/student/profile')
            ->assertOk()
            ->assertJsonPath('data.must_change_password', true);

        $this->postJson('/api/v1/student/logout')->assertOk();
    }

    public function test_change_password_clears_flag_and_sets_changed_at(): void
    {
        $student = Student::factory()->mustChangePassword()->create([
            'password' => 'password123',
        ]);
        Sanctum::actingAs($student, ['*']);

        $this->postJson('/api/v1/student/change-password', [
            'current_password' => 'password123',
            'new_password' => self::STRONG_PASSWORD,
            'new_password_confirmation' => self::STRONG_PASSWORD,
        ])->assertOk()->assertJsonPath('data.must_change_password', false);

        $fresh = $student->fresh();
        $this->assertFalse($fresh->password_must_be_changed);
        $this->assertNotNull($fresh->password_changed_at);
        $this->assertTrue(Hash::check(self::STRONG_PASSWORD, $fresh->password));
    }

    public function test_protected_endpoint_unblocked_after_password_change(): void
    {
        $student = Student::factory()->mustChangePassword()->create([
            'student_id' => 'GU-20240003',
            'password' => 'password123',
        ]);

        $token = $this->loginAndVerify($student, 'password123');

        // Blocked before the change.
        $this->withToken($token)->getJson('/api/v1/student/vehicle')->assertStatus(409);

        $this->withToken($token)->postJson('/api/v1/student/change-password', [
            'current_password' => 'password123',
            'new_password' => self::STRONG_PASSWORD,
            'new_password_confirmation' => self::STRONG_PASSWORD,
        ])->assertOk();

        // Same token, now unblocked.
        $this->withToken($token)->getJson('/api/v1/student/vehicle')->assertOk();
    }

    // =========================================================================
    // Task 2 — Forgot-password request
    // =========================================================================

    public function test_forgot_password_generates_otp_without_exposing_it(): void
    {
        Mail::fake();

        $student = Student::factory()->create(['email' => 'reset@example.com']);

        $response = $this->postJson('/api/v1/student/forgot-password', [
            'email' => 'reset@example.com',
        ])->assertOk()->assertJson(['success' => true]);

        // OTP is never returned in any field of the response.
        $this->assertNull($response->json('otp_code'));
        $this->assertNull($response->json('data.otp_code'));
        $this->assertNull($response->json('reset_token'));

        // A hashed password-reset OTP row was created for this student.
        $this->assertDatabaseHas('student_login_otps', [
            'student_id' => $student->id,
            'purpose' => StudentLoginOtp::PURPOSE_PASSWORD_RESET,
            'used_at' => null,
        ]);

        Mail::assertSent(StudentPasswordResetOtpMail::class, function (StudentPasswordResetOtpMail $mail) {
            return preg_match('/^\d{6}$/', $mail->otpCode) === 1;
        });
    }

    public function test_forgot_password_mail_failure_returns_generic_success(): void
    {
        // When the email exists but SMTP fails, the response must be identical to
        // the generic success path so account existence is not revealed.
        Mail::shouldReceive('to->send')->andThrow(new \RuntimeException('SMTP error'));

        $student = Student::factory()->create(['email' => 'reset@example.com']);

        $response = $this->postJson('/api/v1/student/forgot-password', [
            'email' => 'reset@example.com',
        ])->assertOk()->assertJson([
            'success' => true,
            'message' => 'If an account exists for that email, a password reset code has been sent.',
        ]);

        // OTP is never exposed.
        $this->assertNull($response->json('otp_code'));
        $this->assertNull($response->json('data.otp_code'));

        // The failed OTP record is invalidated (marked used) so it cannot be replayed.
        $this->assertDatabaseHas('student_login_otps', [
            'student_id' => $student->id,
            'purpose' => StudentLoginOtp::PURPOSE_PASSWORD_RESET,
        ]);
        $this->assertDatabaseMissing('student_login_otps', [
            'student_id' => $student->id,
            'purpose' => StudentLoginOtp::PURPOSE_PASSWORD_RESET,
            'used_at' => null,
        ]);
    }

    public function test_forgot_password_unknown_email_returns_generic_success_and_no_mail(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/student/forgot-password', [
            'email' => 'nobody@example.com',
        ])->assertOk()->assertJson(['success' => true]);

        Mail::assertNothingSent();
        $this->assertDatabaseCount('student_login_otps', 0);
    }

    // =========================================================================
    // Task 2 — Forgot-password reset
    // =========================================================================

    /**
     * Request a reset OTP and return the plain code (overwriting the hash so the
     * known value can be submitted in the reset step).
     */
    private function requestResetOtp(Student $student, string $knownCode = '654321'): void
    {
        Mail::fake();

        $this->postJson('/api/v1/student/forgot-password', [
            'email' => $student->email,
        ])->assertOk();

        StudentLoginOtp::where('student_id', $student->id)
            ->where('purpose', StudentLoginOtp::PURPOSE_PASSWORD_RESET)
            ->latest()
            ->firstOrFail()
            ->update(['otp_hash' => Hash::make($knownCode)]);
    }

    public function test_reset_with_valid_otp_updates_password_clears_flag_and_logs_in(): void
    {
        $student = Student::factory()->mustChangePassword()->create([
            'email' => 'reset@example.com',
            'password' => 'password123',
        ]);
        $this->requestResetOtp($student, '654321');

        $response = $this->postJson('/api/v1/student/forgot-password/reset', [
            'email' => 'reset@example.com',
            'otp_code' => '654321',
            'password' => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
        ])->assertOk()
            ->assertJsonPath('data.must_change_password', false)
            ->assertJsonPath('data.student.must_change_password', false);

        // A fresh Sanctum token is returned (logged in).
        $this->assertNotEmpty($response->json('data.token'));

        $fresh = $student->fresh();
        $this->assertTrue(Hash::check(self::STRONG_PASSWORD, $fresh->password));
        $this->assertFalse($fresh->password_must_be_changed);
        $this->assertNotNull($fresh->password_changed_at);

        // OTP marked used.
        $this->assertDatabaseMissing('student_login_otps', [
            'student_id' => $student->id,
            'purpose' => StudentLoginOtp::PURPOSE_PASSWORD_RESET,
            'used_at' => null,
        ]);

        // Returned token actually authenticates.
        $this->withToken($response->json('data.token'))
            ->getJson('/api/v1/student/profile')
            ->assertOk();
    }

    public function test_reset_revokes_existing_tokens(): void
    {
        $student = Student::factory()->create(['email' => 'reset@example.com']);
        $oldToken = $student->createToken('student-api-token')->plainTextToken;
        $this->requestResetOtp($student, '654321');

        $this->postJson('/api/v1/student/forgot-password/reset', [
            'email' => 'reset@example.com',
            'otp_code' => '654321',
            'password' => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
        ])->assertOk();

        // Old token no longer works.
        $this->withToken($oldToken)->getJson('/api/v1/student/profile')->assertUnauthorized();
    }

    public function test_reset_rejects_invalid_otp(): void
    {
        $student = Student::factory()->create(['email' => 'reset@example.com']);
        $this->requestResetOtp($student, '654321');

        $this->postJson('/api/v1/student/forgot-password/reset', [
            'email' => 'reset@example.com',
            'otp_code' => '000000',
            'password' => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
        ])->assertStatus(422);
    }

    public function test_reset_rejects_expired_otp(): void
    {
        $student = Student::factory()->create(['email' => 'reset@example.com']);
        $this->requestResetOtp($student, '654321');

        StudentLoginOtp::where('student_id', $student->id)
            ->where('purpose', StudentLoginOtp::PURPOSE_PASSWORD_RESET)
            ->latest()
            ->firstOrFail()
            ->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/v1/student/forgot-password/reset', [
            'email' => 'reset@example.com',
            'otp_code' => '654321',
            'password' => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
        ])->assertStatus(422);
    }

    public function test_reset_rejects_reused_otp(): void
    {
        $student = Student::factory()->create(['email' => 'reset@example.com']);
        $this->requestResetOtp($student, '654321');

        $payload = [
            'email' => 'reset@example.com',
            'otp_code' => '654321',
            'password' => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
        ];

        // First use succeeds, second use is rejected.
        $this->postJson('/api/v1/student/forgot-password/reset', $payload)->assertOk();
        $this->postJson('/api/v1/student/forgot-password/reset', $payload)->assertStatus(422);
    }

    public function test_reset_requires_confirmed_strong_password(): void
    {
        $student = Student::factory()->create(['email' => 'reset@example.com']);
        $this->requestResetOtp($student, '654321');

        // Mismatched confirmation.
        $this->postJson('/api/v1/student/forgot-password/reset', [
            'email' => 'reset@example.com',
            'otp_code' => '654321',
            'password' => self::STRONG_PASSWORD,
            'password_confirmation' => 'Different1!',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);

        // Weak password.
        $this->postJson('/api/v1/student/forgot-password/reset', [
            'email' => 'reset@example.com',
            'otp_code' => '654321',
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);
    }

    // =========================================================================
    // Task 2 — Cross-flow OTP isolation
    // =========================================================================

    public function test_login_otp_cannot_be_used_for_password_reset(): void
    {
        $student = Student::factory()->create([
            'student_id' => 'GU-20240010',
            'email' => 'iso1@example.com',
            'password' => 'password123',
        ]);

        Mail::fake();

        // Generate a real LOGIN OTP and learn its code.
        $step1 = $this->postJson('/api/v1/student/login', [
            'student_id' => 'GU-20240010',
            'password' => 'password123',
        ])->assertOk()->json();

        StudentLoginOtp::where('challenge_token_hash', hash('sha256', $step1['otp_token']))
            ->firstOrFail()
            ->update(['otp_hash' => Hash::make('111222')]);

        // The login OTP code must NOT be accepted by the password-reset endpoint.
        $this->postJson('/api/v1/student/forgot-password/reset', [
            'email' => 'iso1@example.com',
            'otp_code' => '111222',
            'password' => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
        ])->assertStatus(422);
    }

    public function test_password_reset_otp_cannot_be_used_for_login(): void
    {
        $student = Student::factory()->create([
            'student_id' => 'GU-20240011',
            'email' => 'iso2@example.com',
        ]);

        // Manually craft a password-reset OTP with a KNOWN challenge token + code.
        $challengeToken = str_repeat('b', 64);
        StudentLoginOtp::create([
            'student_id' => $student->id,
            'purpose' => StudentLoginOtp::PURPOSE_PASSWORD_RESET,
            'challenge_token_hash' => hash('sha256', $challengeToken),
            'otp_hash' => Hash::make('333444'),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
        ]);

        // The login verify endpoint is scoped to purpose=login, so this fails.
        $this->postJson('/api/v1/student/login/verify-otp', [
            'otp_token' => $challengeToken,
            'otp_code' => '333444',
        ])->assertUnauthorized();
    }
}
