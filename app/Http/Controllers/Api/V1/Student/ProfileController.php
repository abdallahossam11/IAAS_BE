<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    /**
     * GET /api/v1/student/profile
     *
     * Return the authenticated student's profile with faculty.
     */
    public function show(Request $request): JsonResponse
    {
        $student = $request->user();
        $student->load('faculty');

        return response()->json([
            'success' => true,
            'data' => [
                'full_name' => $student->full_name,
                'student_id' => $student->student_id,
                'email' => $student->email,
                'faculty' => [
                    'id' => $student->faculty->id,
                    'name' => $student->faculty->name,
                ],
                'gpa' => (float) $student->gpa,
                'credits_completed' => $student->credits_completed,
                'credits_required' => $student->credits_required,
            ],
        ]);
    }
}
