<?php

namespace App\Http\Controllers\Api\V1\Student;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class ChangePasswordController extends Controller
{
    /**
     * POST /api/v1/student/change-password
     *
     * Allows an authenticated student to change their password after login.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => [
                'required',
                'string',
                'confirmed',
                Password::min(8)->mixedCase()->numbers()->symbols(),
            ],
        ]);

        $student = $request->user();

        if (! $student || ! Hash::check($validated['current_password'], $student->password)) {
            return response()->json([
                'success' => false,
                'message' => 'The current password is incorrect.',
            ], 422);
        }

        // The Student model has a "hashed" cast for password, so assigning
        // the plain new password here stores it hashed in the database.
        $student->password = $validated['new_password'];
        $student->save();

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully.',
        ]);
    }
}
