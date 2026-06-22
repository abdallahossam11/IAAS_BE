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
                // Date only (YYYY-MM-DD) when set, null for not-yet-backfilled students.
                'date_of_birth' => $student->date_of_birth?->format('Y-m-d'),
                'must_change_password' => (bool) $student->password_must_be_changed,
                'faculty' => [
                    'id' => $student->faculty->id,
                    'name' => $student->faculty->name,
                    'sector' => $student->faculty->sector,
                    'field' => $student->faculty->field,
                    'credit_hours' => $student->faculty->credit_hours,
                ],
                'gpa' => (float) $student->gpa,
                'credits_completed' => $student->credits_completed,
                // Student snapshot — independent of the live faculty value above.
                'credits_required' => $student->credits_required,
            ],
        ]);
    }
}
