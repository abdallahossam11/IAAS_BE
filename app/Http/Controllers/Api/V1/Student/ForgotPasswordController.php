<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Controller;
use App\Mail\StudentPasswordResetOtpMail;
use App\Models\Student;
use App\Models\StudentLoginOtp;
use App\Support\Security\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;

class ForgotPasswordController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    /**
     * POST /api/v1/student/forgot-password
     *
     * Step 1 of the forgot-password flow. Accepts an email, and — if it belongs
     * to a student — generates a password-reset OTP and emails it. The response
     * is intentionally generic so it never reveals whether an account exists.
     */
    public function request(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $student = Student::where('email', $request->email)->first();

        // Generic response shared by both the "account exists" and "does not
        // exist" branches to avoid account enumeration.
        $generic = response()->json([
            'success' => true,
            'message' => 'If an account exists for that email, a password reset code has been sent.',
        ]);

        if (! $student) {
            return $generic;
        }

        // Invalidate any previous unused password-reset OTPs for this student so
        // only the newest code can be used.
        StudentLoginOtp::where('student_id', $student->id)
            ->where('purpose', StudentLoginOtp::PURPOSE_PASSWORD_RESET)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $otpCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $otpRecord = StudentLoginOtp::create([
            'student_id' => $student->id,
            'purpose' => StudentLoginOtp::PURPOSE_PASSWORD_RESET,
            // No client-facing challenge token for this flow; the reset step is
            // keyed by email + code. Store a random hash to satisfy the column.
            'challenge_token_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'otp_hash' => Hash::make($otpCode),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 512),
        ]);

        try {
            Mail::to($student->email)->send(new StudentPasswordResetOtpMail($otpCode));
        } catch (\Throwable $e) {
            // Invalidate the record so a non-delivered code cannot be replayed.
            $otpRecord->update(['used_at' => now()]);
            Log::error('Failed to send StudentPasswordResetOtpMail', [
                'student_id' => $student->id,
                'error' => $e->getMessage(),
            ]);

            // Return the SAME generic response as the unknown-email branch. A
            // distinct error status here would reveal that the account exists.
            return $generic;
        }

        AuditLog::info('student_forgot_password_requested', [
            'target_student_id' => $student->id,
            'ip' => $request->ip(),
            'result' => 'success',
        ]);

        return $generic;
    }

    /**
     * POST /api/v1/student/forgot-password/reset
     *
     * Step 2 of the forgot-password flow. Verifies the emailed OTP, sets the new
     * password, clears the first-login gate, revokes existing tokens, and logs
     * the student in by returning a fresh Sanctum token.
     */
    public function reset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'otp_code' => ['required', 'digits:6'],
            // Same password policy as the authenticated change-password endpoint.
            'password' => ['required', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
        ]);

        $student = Student::where('email', $validated['email'])->first();

        if (! $student) {
            return $this->invalidResponse();
        }

        // Newest unused password-reset OTP for this student only.
        $otpRecord = StudentLoginOtp::where('student_id', $student->id)
            ->where('purpose', StudentLoginOtp::PURPOSE_PASSWORD_RESET)
            ->whereNull('used_at')
            ->latest()
            ->first();

        if (! $otpRecord || $otpRecord->isExpired()) {
            return $this->invalidResponse();
        }

        // Increment first (before checking) to throttle brute-force attempts.
        $otpRecord->increment('attempts');
        $otpRecord->refresh();

        if ($otpRecord->hasExceededAttempts(self::MAX_ATTEMPTS)) {
            return response()->json([
                'success' => false,
                'message' => 'Too many attempts. Please request a new reset code.',
            ], 429);
        }

        if (! Hash::check($validated['otp_code'], $otpRecord->otp_hash)) {
            AuditLog::warning('student_password_reset_failed', [
                'target_student_id' => $student->id,
                'attempts' => $otpRecord->attempts,
                'ip' => $request->ip(),
                'result' => 'fail',
            ]);

            return $this->invalidResponse();
        }

        // Mark OTP used so it cannot be replayed.
        $otpRecord->update(['used_at' => now()]);

        // Set the new password (hashed via the model cast) and clear the gate.
        $student->password = $validated['password'];
        $student->password_must_be_changed = false;
        $student->password_changed_at = now();
        $student->save();

        // Revoke any existing Sanctum tokens, then issue a fresh one so the
        // student is logged in immediately after the reset.
        $student->tokens()->delete();
        $token = $student->createToken('student-api-token')->plainTextToken;

        AuditLog::info('student_password_reset_success', [
            'target_student_id' => $student->id,
            'ip' => $request->ip(),
            'result' => 'success',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully.',
            'data' => [
                'token' => $token,
                'must_change_password' => false,
                'student' => [
                    'id' => $student->id,
                    'student_id' => $student->student_id,
                    'full_name' => $student->full_name,
                    'email' => $student->email,
                    'must_change_password' => false,
                ],
            ],
        ]);
    }

    private function invalidResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Invalid or expired verification code.',
        ], 422);
    }
}
