<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

class StoreVehicleRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Shared rule that blocks HTML control/tag characters.
        $noHtml = ['string', 'regex:/^[^<>"\'\/\\\\]+$/u'];

        return [
            // Allows Unicode letters (Arabic + Latin), Unicode digits, space, hyphen, dot.
            // Explicitly blocks <>"'/\ via the positive character class.
            'plate_number' => [
                'required',
                'string',
                'max:30',
                'regex:/^[\p{L}\p{N} \-\.]+$/u',
            ],

            'vehicle_type' => array_merge(['required', 'max:100'], $noHtml),

            'vehicle_model' => array_merge(['required', 'max:100'], $noHtml),

            'vehicle_color' => array_merge(['required', 'max:50'], $noHtml),
        ];
    }

    public function messages(): array
    {
        return [
            'plate_number.regex' => 'The plate number may only contain letters, digits, spaces, hyphens, and dots.',
            'vehicle_type.regex' => 'The vehicle type must not contain HTML characters.',
            'vehicle_model.regex' => 'The vehicle model must not contain HTML characters.',
            'vehicle_color.regex' => 'The vehicle colour must not contain HTML characters.',
        ];
    }
}
