<?php

namespace App\Http\Controllers\Api\V1\Gate;

use App\Http\Controllers\Controller;
use App\Models\VehicleRequest;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleAccessController extends Controller
{
    /**
     * Check if a vehicle is allowed access based on the OCR plate number.
     */
    public function check(Request $request): JsonResponse
    {
        $request->validate([
            'OCR' => 'required|string|max:100',
        ]);

        $ocrPlate = $this->normalizePlate($request->input('OCR'));

        // Retrieve all currently active, approved permits
        $today = Carbon::today();
        $activePermits = VehicleRequest::with('student.faculty')
            ->where('status', 'approved')
            ->whereNotNull('semester_start_date')
            ->whereNotNull('semester_end_date')
            ->where('semester_start_date', '<=', $today)
            ->where('semester_end_date', '>=', $today)
            ->get();

        // Find the first permit where the normalized DB plate matches the normalized OCR plate
        $matchedPermit = $activePermits->first(function ($permit) use ($ocrPlate) {
            return $this->normalizePlate($permit->plate_number) === $ocrPlate;
        });

        if ($matchedPermit) {
            return response()->json([
                'success' => true,
                'access' => 'allowed',
                'message' => 'Vehicle permit is approved and valid.',
                'data' => [
                    'plate_number' => $matchedPermit->plate_number,
                    'normalized_plate' => $ocrPlate,
                    'student' => [
                        'student_id' => $matchedPermit->student->student_id,
                        'full_name' => $matchedPermit->student->full_name,
                        'faculty' => $matchedPermit->student->faculty->name,
                    ],
                    'permit' => [
                        'id' => $matchedPermit->id,
                        'valid_from' => $matchedPermit->semester_start_date->toDateString(),
                        'valid_until' => $matchedPermit->semester_end_date->toDateString(),
                    ],
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'access' => 'denied',
            'message' => 'No approved valid vehicle permit found for this plate.',
            'data' => [
                'plate_number' => $request->input('OCR'),
                'normalized_plate' => $ocrPlate,
            ],
        ]);
    }

    /**
     * Normalize a vehicle plate string for robust matching.
     */
    public function normalizePlate(string $plate): string
    {
        // Convert Arabic and Persian digits to English digits
        $arabicDigits = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $persianDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $englishDigits = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        $plate = str_replace($arabicDigits, $englishDigits, $plate);
        $plate = str_replace($persianDigits, $englishDigits, $plate);

        // Remove spaces, dashes, underscores, slashes, backslashes, pipes, and dots
        $plate = preg_replace('/[\s\-\_\\\\\/\|\.]+/u', '', $plate);

        // Normalize Arabic letter variants
        $plate = str_replace(['أ', 'إ', 'آ'], 'ا', $plate);
        $plate = str_replace('ى', 'ي', $plate);
        $plate = str_replace('ة', 'ه', $plate);

        // Lowercase final string
        return mb_strtolower($plate);
    }
}
