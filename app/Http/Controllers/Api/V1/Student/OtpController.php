<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Controller;
use App\Models\StudentLoginOtp;
use App\Support\Security\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class OtpController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    /**
     * POST /api/v1/student/login/verify-otp
     *
     * Step 2 of the two-step login flow.
     * Accepts an otp_token (challenge) + otp_code, issues a Sanctum token on success.
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'otp_token' => ['required', 'string', 'size:64'],
            'otp_code' => ['required', 'digits:6'],
        ]);

        // Find the OTP record matching the hashed challenge token. Scoped to the
        // login purpose so a forgot-password OTP can never be used to log in.
        $challengeHash = hash('sha256', $request->otp_token);
        $otpRecord = StudentLoginOtp::where('challenge_token_hash', $challengeHash)
            ->where('purpose', StudentLoginOtp::PURPOSE_LOGIN)
            ->first();

        if (! $otpRecord) {
            return $this->invalidResponse();
        }

        if ($otpRecord->isUsed()) {
            return $this->invalidResponse();
        }

        if ($otpRecord->isExpired()) {
            return $this->invalidResponse();
        }

        // Increment attempt counter first (before checking) to prevent timing attacks
        $otpRecord->increment('attempts');
        $otpRecord->refresh();

        if ($otpRecord->hasExceededAttempts(self::MAX_ATTEMPTS)) {
            return response()->json([
                'success' => false,
                'message' => 'Too many attempts. Please log in again.',
            ], 429);
        }

        if (! Hash::check($request->otp_code, $otpRecord->otp_hash)) {
            AuditLog::warning('otp_verification_failed', [
                'actor_student_id' => $otpRecord->student_id,
                'attempts' => $otpRecord->attempts,
                'ip' => $request->ip(),
                'result' => 'fail',
            ]);

            return $this->invalidResponse();
        }

        // Mark as used
        $otpRecord->update(['used_at' => now()]);

        $student = $otpRecord->student;

        AuditLog::info('otp_verification_success', [
            'actor_student_id' => $student->id,
            'ip' => $request->ip(),
            'result' => 'success',
        ]);

        $token = $student->createToken('student-api-token')->plainTextToken;

        $mustChangePassword = (bool) $student->password_must_be_changed;

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'token' => $token,
                'must_change_password' => $mustChangePassword,
                'student' => [
                    'id' => $student->id,
                    'student_id' => $student->student_id,
                    'full_name' => $student->full_name,
                    'email' => $student->email,
                    'must_change_password' => $mustChangePassword,
                ],
            ],
        ]);
    }

    private function invalidResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Invalid or expired verification code.',
        ], 401);
    }
}
