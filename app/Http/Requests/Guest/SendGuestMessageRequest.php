<?php

namespace App\Http\Requests\Guest;

use Illuminate\Foundation\Http\FormRequest;

class SendGuestMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $token = $this->header('X-Guest-Token');

        if ($token !== null) {
            $this->merge(['guest_token' => $token]);
        }
    }

    public function rules(): array
    {
        return [
            'message'     => ['required', 'string', 'max:' . config('chat.max_message_length', 4000)],
            'guest_token' => ['nullable', 'string', 'size:64', 'regex:/^[A-Za-z0-9]{64}$/'],
        ];
    }
}
