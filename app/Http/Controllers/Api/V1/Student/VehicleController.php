<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Controller;
use App\Models\VehicleRequest;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleController extends Controller
{
    /**
     * GET /api/v1/student/vehicle
     *
     * Return the current vehicle state for the authenticated student.
     *
     * Logic:
     * - Get latest vehicle request (by created_at desc).
     * - none     → no request exists, or latest approved is expired.
     * - pending  → latest request is pending.
     * - approved → latest is approved AND today is within semester dates.
     * - rejected → latest request is rejected.
     */
    public function state(Request $request): JsonResponse
    {
        $student = $request->user();
        $latest = $student->vehicleRequests()->latest()->first();

        // No request exists
        if (! $latest) {
            return response()->json([
                'success' => true,
                'status' => 'none',
                'data' => null,
            ]);
        }

        $today = Carbon::today();

        // Pending
        if ($latest->status === 'pending') {
            return response()->json([
                'success' => true,
                'status' => 'pending',
                'data' => [
                    'id' => $latest->id,
                    'vehicle_type' => $latest->vehicle_type,
                    'vehicle_model' => $latest->vehicle_model,
                    'vehicle_color' => $latest->vehicle_color,
                    'plate_number' => $latest->plate_number,
                    'submitted_at' => $latest->created_at->toDateString(),
                ],
            ]);
        }

        // Approved — check if active
        // Current business rule: only currently active approved permits block new requests.
        // Future-approved permits are not treated as active unless requirements change.
        if ($latest->status === 'approved') {
            $isActive = $latest->semester_start_date
                && $latest->semester_end_date
                && $today->between($latest->semester_start_date, $latest->semester_end_date);

            if ($isActive) {
                return response()->json([
                    'success' => true,
                    'status' => 'approved',
                    'data' => [
                        'id' => $latest->id,
                        'vehicle_type' => $latest->vehicle_type,
                        'vehicle_model' => $latest->vehicle_model,
                        'vehicle_color' => $latest->vehicle_color,
                        'plate_number' => $latest->plate_number,
                        'approved_at' => $latest->approved_at?->toDateString(),
                        'valid_from' => $latest->semester_start_date->toDateString(),
                        'valid_until' => $latest->semester_end_date->toDateString(),
                    ],
                ]);
            }

            // Approved but expired → treat as none
            return response()->json([
                'success' => true,
                'status' => 'none',
                'data' => null,
            ]);
        }

        // Rejected
        if ($latest->status === 'rejected') {
            return response()->json([
                'success' => true,
                'status' => 'rejected',
                'data' => [
                    'id' => $latest->id,
                    'vehicle_type' => $latest->vehicle_type,
                    'vehicle_model' => $latest->vehicle_model,
                    'vehicle_color' => $latest->vehicle_color,
                    'plate_number' => $latest->plate_number,
                    'rejection_reason' => $latest->rejection_reason,
                    'rejected_at' => $latest->updated_at->toDateString(),
                ],
            ]);
        }

        // Fallback (should not be reached)
        return response()->json([
            'success' => true,
            'status' => 'none',
            'data' => null,
        ]);
    }

    /**
     * POST /api/v1/student/vehicle-requests
     *
     * Submit a new vehicle access request.
     *
     * Business rules:
     * - Cannot submit if there is any pending request.
     * - Cannot submit if there is an active approved permit (today between semester dates).
     * - Can submit if latest is rejected.
     * - Can submit if previous approved permit is expired.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'vehicle_type' => 'required|string',
            'vehicle_model' => 'required|string',
            'vehicle_color' => 'required|string',
            'plate_number' => 'required|string',
        ]);

        $student = $request->user();
        $today = Carbon::today();

        // Check for any pending request
        $hasPending = $student->vehicleRequests()
            ->where('status', 'pending')
            ->exists();

        if ($hasPending) {
            return response()->json([
                'success' => false,
                'message' => 'You already have a pending vehicle request or active permit.',
            ], 422);
        }

        // Check for any active approved permit
        // Current business rule: only currently active approved permits block new requests.
        // Future-approved permits are not treated as active unless requirements change.
        $hasActivePermit = $student->vehicleRequests()
            ->where('status', 'approved')
            ->whereNotNull('semester_start_date')
            ->whereNotNull('semester_end_date')
            ->where('semester_start_date', '<=', $today)
            ->where('semester_end_date', '>=', $today)
            ->exists();

        if ($hasActivePermit) {
            return response()->json([
                'success' => false,
                'message' => 'You already have a pending vehicle request or active permit.',
            ], 422);
        }

        // Create the new vehicle request
        $vehicleRequest = VehicleRequest::create([
            'student_id' => $student->id,
            'vehicle_type' => $request->vehicle_type,
            'vehicle_model' => $request->vehicle_model,
            'vehicle_color' => $request->vehicle_color,
            'plate_number' => $request->plate_number,
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Vehicle request submitted successfully',
            'data' => [
                'id' => $vehicleRequest->id,
                'status' => 'pending',
            ],
        ]);
    }

    /**
     * GET /api/v1/student/vehicle-requests/history
     *
     * Return all vehicle requests for the authenticated student, newest first.
     */
    public function history(Request $request): JsonResponse
    {
        $student = $request->user();

        $requests = $student->vehicleRequests()
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($vr) {
                return [
                    'id' => $vr->id,
                    'vehicle_type' => $vr->vehicle_type,
                    'vehicle_model' => $vr->vehicle_model,
                    'vehicle_color' => $vr->vehicle_color,
                    'plate_number' => $vr->plate_number,
                    'status' => $vr->status,
                    'valid_from' => $vr->semester_start_date?->toDateString(),
                    'valid_until' => $vr->semester_end_date?->toDateString(),
                    'rejection_reason' => $vr->rejection_reason,
                    'created_at' => $vr->created_at->toDateString(),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $requests,
        ]);
    }
}
