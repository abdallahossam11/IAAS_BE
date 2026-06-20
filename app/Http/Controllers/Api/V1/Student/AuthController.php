<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Controller;
use App\Mail\StudentLoginOtpMail;
use App\Models\Student;
use App\Models\StudentLoginOtp;
use App\Support\Security\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * POST /api/v1/student/login
     *
     * Step 1 of the two-step login flow.
     * Validates student_id + password, generates an OTP, sends it by email,
     * and returns an opaque challenge token. No Sanctum token is issued yet.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'student_id' => 'required|string',
            'password' => 'required|string',
        ]);

        $student = Student::where('student_id', $request->student_id)->first();

        if (! $student || ! Hash::check($request->password, $student->password)) {
            AuditLog::warning('student_login_failed', [
                'actor_student_id' => $request->student_id,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'result' => 'fail',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid student ID or password',
            ], 401);
        }

        // Invalidate any previous unused OTPs for this student
        StudentLoginOtp::where('student_id', $student->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        // Generate OTP and challenge token
        $otpCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $challengeToken = Str::random(64);

        $otpRecord = StudentLoginOtp::create([
            'student_id' => $student->id,
            'challenge_token_hash' => hash('sha256', $challengeToken),
            'otp_hash' => Hash::make($otpCode),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 512),
        ]);

        // Send OTP to student's email — do not expose the code in the response.
        // If delivery fails, invalidate the OTP record so the challenge token
        // cannot be replayed and return 503 — returning a challenge without a
        // delivered code would be a false success.
        try {
            Mail::to($student->email)->send(new StudentLoginOtpMail($otpCode));
        } catch (\Throwable $e) {
            $otpRecord->update(['used_at' => now()]);
            Log::error('Failed to send StudentLoginOtpMail', [
                'student_id' => $student->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to send verification code. Please try again later.',
            ], 503);
        }

        return response()->json([
            'success' => true,
            'requires_otp' => true,
            'otp_token' => $challengeToken,
            'message' => 'Verification code sent to your email.',
        ]);
    }

    /**
     * POST /api/v1/student/logout
     *
     * Revoke the current access token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }
}
