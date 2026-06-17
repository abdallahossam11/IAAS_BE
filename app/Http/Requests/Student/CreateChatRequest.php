<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

class CreateChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message'           => ['required', 'string', 'max:' . config('chat.max_message_length', 3000)],
            'client_message_id' => ['required', 'uuid'],
        ];
    }
}
