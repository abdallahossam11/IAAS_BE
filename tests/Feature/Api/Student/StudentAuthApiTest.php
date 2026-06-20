<?php

namespace Tests\Feature\Api\Student;

use App\Mail\StudentLoginOtpMail;
use App\Models\Student;
use App\Models\StudentLoginOtp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression tests for the Student Auth API (two-step login with OTP).
 *
 * Step 1 — POST /api/v1/student/login
 *   Returns: {success, requires_otp, otp_token, message}
 *   No Sanctum token is issued at this stage.
 *
 * Step 2 — POST /api/v1/student/login/verify-otp
 *   Accepts: {otp_token, otp_code}
 *   Returns: {success, message, data.token, data.student}
 *
 * Logout — POST /api/v1/student/logout
 *   Requires Sanctum token.
 */
class StudentAuthApiTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Helper: complete the full two-step login and return the Sanctum token.
    // -------------------------------------------------------------------------

    private function loginAndVerifyOtp(Student $student, string $password): string
    {
        Mail::fake();

        $step1 = $this->postJson('/api/v1/student/login', [
            'student_id' => $student->student_id,
            'password' => $password,
        ])->assertOk()->json();

        $challengeToken = $step1['otp_token'];

        // Retrieve the plain OTP from the DB record (stored hashed, so we
        // create a new one directly for the test helper).
        $otp = StudentLoginOtp::where(
            'challenge_token_hash', hash('sha256', $challengeToken)
        )->firstOrFail();

        // Replace OTP hash with a known value so we can verify in step 2
        $knownCode = '123456';
        $otp->update(['otp_hash' => Hash::make($knownCode)]);

        $step2 = $this->postJson('/api/v1/student/login/verify-otp', [
            'otp_token' => $challengeToken,
            'otp_code' => $knownCode,
        ])->assertOk()->json();

        return $step2['data']['token'];
    }

    // =========================================================================
    // A) Login step 1 — OTP challenge
    // =========================================================================

    public function test_valid_credentials_return_requires_otp_not_token(): void
    {
        Mail::fake();

        $student = Student::factory()->create([
            'student_id' => 'GU-20240001',
            'password' => 'secret1234',
        ]);

        $response = $this->postJson('/api/v1/student/login', [
            'student_id' => 'GU-20240001',
            'password' => 'secret1234',
        ])->assertOk();

        $response->assertJson(['success' => true, 'requires_otp' => true]);
        $this->assertNotEmpty($response->json('otp_token'));
        $this->assertNull($response->json('data.token'), 'Sanctum token must NOT be issued at step 1');
    }

    public function test_login_sends_otp_email(): void
    {
        Mail::fake();

        Student::factory()->create([
            'student_id' => 'GU-20240001',
            'password' => 'secret1234',
        ]);

        $this->postJson('/api/v1/student/login', [
            'student_id' => 'GU-20240001',
            'password' => 'secret1234',
        ])->assertOk();

        Mail::assertSent(StudentLoginOtpMail::class);
    }

    public function test_wrong_password_does_not_send_otp(): void
    {
        Mail::fake();

        Student::factory()->create([
            'student_id' => 'GU-20240001',
            'password' => 'secret1234',
        ]);

        $this->postJson('/api/v1/student/login', [
            'student_id' => 'GU-20240001',
            'password' => 'wrongpassword',
        ])->assertUnauthorized();

        Mail::assertNotSent(StudentLoginOtpMail::class);
    }

    public function test_otp_not_returned_in_login_response(): void
    {
        Mail::fake();

        Student::factory()->create([
            'student_id' => 'GU-20240001',
            'password' => 'secret1234',
        ]);

        $response = $this->postJson('/api/v1/student/login', [
            'student_id' => 'GU-20240001',
            'password' => 'secret1234',
        ])->assertOk();

        $this->assertNull($response->json('otp_code'), 'OTP code must never be in the response');
    }

    // =========================================================================
    // B) Login step 2 — OTP verification
    // =========================================================================

    public function test_correct_otp_returns_sanctum_token(): void
    {
        Mail::fake();

        $student = Student::factory()->create([
            'student_id' => 'GU-20240001',
            'password' => 'secret1234',
        ]);

        $step1 = $this->postJson('/api/v1/student/login', [
            'student_id' => 'GU-20240001',
            'password' => 'secret1234',
        ])->assertOk()->json();

        $challengeToken = $step1['otp_token'];

        $otp = StudentLoginOtp::where(
            'challenge_token_hash', hash('sha256', $challengeToken)
        )->firstOrFail();
        $knownCode = '654321';
        $otp->update(['otp_hash' => Hash::make($knownCode)]);

        $step2 = $this->postJson('/api/v1/student/login/verify-otp', [
            'otp_token' => $challengeToken,
            'otp_code' => $knownCode,
        ])->assertOk();

        $this->assertNotEmpty($step2->json('data.token'));
        $step2->assertJsonStructure([
            'data' => ['token', 'student' => ['id', 'student_id', 'full_name', 'email']],
        ]);
    }

    public function test_wrong_otp_is_rejected(): void
    {
        Mail::fake();

        Student::factory()->create([
            'student_id' => 'GU-20240001',
            'password' => 'secret1234',
        ]);

        $step1 = $this->postJson('/api/v1/student/login', [
            'student_id' => 'GU-20240001',
            'password' => 'secret1234',
        ])->assertOk()->json();

        $this->postJson('/api/v1/student/login/verify-otp', [
            'otp_token' => $step1['otp_token'],
            'otp_code' => '000000',
        ])->assertUnauthorized();
    }

    public function test_expired_otp_is_rejected(): void
    {
        Mail::fake();

        $student = Student::factory()->create([
            'student_id' => 'GU-20240001',
            'password' => 'secret1234',
        ]);

        $step1 = $this->postJson('/api/v1/student/login', [
            'student_id' => 'GU-20240001',
            'password' => 'secret1234',
        ])->assertOk()->json();

        $challengeToken = $step1['otp_token'];

        $otp = StudentLoginOtp::where(
            'challenge_token_hash', hash('sha256', $challengeToken)
        )->firstOrFail();
        $knownCode = '111111';
        $otp->update([
            'otp_hash' => Hash::make($knownCode),
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/v1/student/login/verify-otp', [
            'otp_token' => $challengeToken,
            'otp_code' => $knownCode,
        ])->assertUnauthorized();
    }

    public function test_used_otp_cannot_be_reused(): void
    {
        Mail::fake();

        $student = Student::factory()->create([
            'student_id' => 'GU-20240001',
            'password' => 'secret1234',
        ]);

        $step1 = $this->postJson('/api/v1/student/login', [
            'student_id' => 'GU-20240001',
            'password' => 'secret1234',
        ])->assertOk()->json();

        $challengeToken = $step1['otp_token'];

        $otp = StudentLoginOtp::where(
            'challenge_token_hash', hash('sha256', $challengeToken)
        )->firstOrFail();
        $knownCode = '222222';
        $otp->update(['otp_hash' => Hash::make($knownCode)]);

        // First use — succeeds
        $this->postJson('/api/v1/student/login/verify-otp', [
            'otp_token' => $challengeToken,
            'otp_code' => $knownCode,
        ])->assertOk();

        // Second use — must be rejected
        $this->postJson('/api/v1/student/login/verify-otp', [
            'otp_token' => $challengeToken,
            'otp_code' => $knownCode,
        ])->assertUnauthorized();
    }

    public function test_too_many_attempts_returns_429(): void
    {
        // After MAX_ATTEMPTS=5 wrong attempts (attempts counter reaches 5),
        // the NEXT (6th) submit increments to 6 > 5 and triggers lockout.
        // The 5th wrong attempt itself still returns 401, not 429.
        Mail::fake();

        Student::factory()->create([
            'student_id' => 'GU-20240001',
            'password' => 'secret1234',
        ]);

        $step1 = $this->postJson('/api/v1/student/login', [
            'student_id' => 'GU-20240001',
            'password' => 'secret1234',
        ])->assertOk()->json();

        $challengeToken = $step1['otp_token'];

        $otp = StudentLoginOtp::where(
            'challenge_token_hash', hash('sha256', $challengeToken)
        )->firstOrFail();

        // Set to exactly 5 so next submit → 6 > 5 → 429
        $otp->update(['attempts' => 5]);

        $this->postJson('/api/v1/student/login/verify-otp', [
            'otp_token' => $challengeToken,
            'otp_code' => '999999',
        ])->assertStatus(429);
    }

    public function test_fifth_wrong_attempt_still_returns_401_not_429(): void
    {
        // MAX_ATTEMPTS=5 means the 5th wrong attempt returns 401 (still valid
        // attempt), and only the 6th attempt triggers lockout (429).
        Mail::fake();

        Student::factory()->create([
            'student_id' => 'GU-20240001',
            'password' => 'secret1234',
        ]);

        $step1 = $this->postJson('/api/v1/student/login', [
            'student_id' => 'GU-20240001',
            'password' => 'secret1234',
        ])->assertOk()->json();

        $challengeToken = $step1['otp_token'];

        $otp = StudentLoginOtp::where(
            'challenge_token_hash', hash('sha256', $challengeToken)
        )->firstOrFail();

        // Set to 4 so next wrong submit → 5, 5 > 5 is false → 401
        $otp->update(['attempts' => 4]);

        $this->postJson('/api/v1/student/login/verify-otp', [
            'otp_token' => $challengeToken,
            'otp_code' => '000000', // deliberately wrong
        ])->assertUnauthorized();
    }

    public function test_invalid_otp_token_is_rejected(): void
    {
        // Token must be exactly 64 chars — a well-formed but non-matching token
        // should return 401 (record not found), not a 422 validation error.
        $nonExistentToken = str_repeat('a', 64);

        $this->postJson('/api/v1/student/login/verify-otp', [
            'otp_token' => $nonExistentToken,
            'otp_code' => '123456',
        ])->assertUnauthorized();
    }

    // =========================================================================
    // F) OTP strict input validation (FIX 4)
    // =========================================================================

    public function test_otp_code_must_be_exactly_6_digits(): void
    {
        $token = str_repeat('a', 64);

        // Too short
        $this->postJson('/api/v1/student/login/verify-otp', [
            'otp_token' => $token,
            'otp_code' => '12345',
        ])->assertUnprocessable()->assertJsonValidationErrors(['otp_code']);

        // Too long
        $this->postJson('/api/v1/student/login/verify-otp', [
            'otp_token' => $token,
            'otp_code' => '1234567',
        ])->assertUnprocessable()->assertJsonValidationErrors(['otp_code']);
    }

    public function test_non_digit_otp_code_is_rejected(): void
    {
        $this->postJson('/api/v1/student/login/verify-otp', [
            'otp_token' => str_repeat('a', 64),
            'otp_code' => 'abcdef',
        ])->assertUnprocessable()->assertJsonValidationErrors(['otp_code']);
    }

    public function test_otp_token_must_be_exactly_64_chars(): void
    {
        // Too short
        $this->postJson('/api/v1/student/login/verify-otp', [
            'otp_token' => str_repeat('a', 63),
            'otp_code' => '123456',
        ])->assertUnprocessable()->assertJsonValidationErrors(['otp_token']);

        // Too long
        $this->postJson('/api/v1/student/login/verify-otp', [
            'otp_token' => str_repeat('a', 65),
            'otp_code' => '123456',
        ])->assertUnprocessable()->assertJsonValidationErrors(['otp_token']);
    }

    // =========================================================================
    // G) OTP mail failure returns 503 and invalidates challenge (FIX 3)
    // =========================================================================

    public function test_otp_mail_failure_returns_503(): void
    {
        Mail::shouldReceive('to->send')->andThrow(new \RuntimeException('SMTP error'));

        Student::factory()->create([
            'student_id' => 'GU-20240001',
            'password' => 'secret1234',
        ]);

        $this->postJson('/api/v1/student/login', [
            'student_id' => 'GU-20240001',
            'password' => 'secret1234',
        ])->assertStatus(503)
            ->assertJson(['success' => false]);
    }

    public function test_otp_mail_failure_invalidates_the_otp_record(): void
    {
        Mail::shouldReceive('to->send')->andThrow(new \RuntimeException('SMTP error'));

        Student::factory()->create([
            'student_id' => 'GU-20240001',
            'password' => 'secret1234',
        ]);

        $this->postJson('/api/v1/student/login', [
            'student_id' => 'GU-20240001',
            'password' => 'secret1234',
        ])->assertStatus(503);

        // The OTP record must be marked used so the challenge cannot be replayed
        $this->assertDatabaseMissing('student_login_otps', [
            'used_at' => null,
        ]);
    }

    public function test_profile_requires_otp_verified_token(): void
    {
        Mail::fake();

        // Step 1 only — no Sanctum token yet
        Student::factory()->create([
            'student_id' => 'GU-20240001',
            'password' => 'secret1234',
        ]);

        $this->postJson('/api/v1/student/login', [
            'student_id' => 'GU-20240001',
            'password' => 'secret1234',
        ])->assertOk();

        // Attempting to access profile without a Sanctum token must fail
        $this->getJson('/api/v1/student/profile')->assertUnauthorized();
    }

    // =========================================================================
    // C) Login — failure and validation
    // =========================================================================

    public function test_login_fails_with_wrong_password(): void
    {
        Student::factory()->create([
            'student_id' => 'GU-20240001',
            'password' => 'secret1234',
        ]);

        $this->postJson('/api/v1/student/login', [
            'student_id' => 'GU-20240001',
            'password' => 'wrongpassword',
        ])
            ->assertUnauthorized()
            ->assertJson(['success' => false]);
    }

    public function test_login_fails_with_nonexistent_student_id(): void
    {
        $this->postJson('/api/v1/student/login', [
            'student_id' => 'GU-DOES-NOT-EXIST',
            'password' => 'anypassword',
        ])
            ->assertUnauthorized()
            ->assertJson(['success' => false]);
    }

    public function test_login_requires_both_fields(): void
    {
        $this->postJson('/api/v1/student/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['student_id', 'password']);
    }

    public function test_login_requires_student_id(): void
    {
        $this->postJson('/api/v1/student/login', ['password' => 'secret1234'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['student_id']);
    }

    public function test_login_requires_password(): void
    {
        $this->postJson('/api/v1/student/login', ['student_id' => 'GU-20240001'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_factory_default_password_works_for_login(): void
    {
        Mail::fake();

        Student::factory()->create(['student_id' => 'GU-20240001']);

        $this->postJson('/api/v1/student/login', [
            'student_id' => 'GU-20240001',
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJson(['success' => true, 'requires_otp' => true]);
    }

    // =========================================================================
    // D) Logout
    // =========================================================================

    public function test_authenticated_student_can_logout(): void
    {
        Sanctum::actingAs(Student::factory()->create(), ['*']);

        $this->postJson('/api/v1/student/logout')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/v1/student/logout')
            ->assertUnauthorized();
    }

    public function test_token_is_revoked_after_logout(): void
    {
        $student = Student::factory()->create([
            'student_id' => 'GU-20240001',
            'password' => 'secret1234',
        ]);

        $token = $this->loginAndVerifyOtp($student, 'secret1234');

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->withToken($token)->postJson('/api/v1/student/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    // =========================================================================
    // E) OTP mail content asserts
    // =========================================================================

    public function test_otp_mail_does_not_expose_hash(): void
    {
        Mail::fake();

        Student::factory()->create([
            'student_id' => 'GU-20240001',
            'password' => 'secret1234',
        ]);

        $this->postJson('/api/v1/student/login', [
            'student_id' => 'GU-20240001',
            'password' => 'secret1234',
        ])->assertOk();

        Mail::assertSent(StudentLoginOtpMail::class, function (StudentLoginOtpMail $mail) {
            // The OTP code must be numeric and 6 digits, not a bcrypt hash
            return preg_match('/^\d{6}$/', $mail->otpCode) === 1;
        });
    }
}
