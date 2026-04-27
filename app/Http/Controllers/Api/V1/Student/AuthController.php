<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * POST /api/v1/student/login
     *
     * Authenticate student using student_id + password.
     * Returns a Sanctum API token on success.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'student_id' => 'required|string',
            'password' => 'required|string',
        ]);

        $student = Student::where('student_id', $request->student_id)->first();

        if (! $student || ! Hash::check($request->password, $student->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid student ID or password',
            ], 401);
        }

        $token = $student->createToken('student-api-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'token' => $token,
                'student' => [
                    'id' => $student->id,
                    'student_id' => $student->student_id,
                    'full_name' => $student->full_name,
                    'email' => $student->email,
                ],
            ],
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
