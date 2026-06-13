<?php

namespace App\Services\Ai;

use App\Models\ChatAiRequest;
use App\Models\ChatMessage;

class StudentPayloadBuilder
{
    public function __construct(private readonly ChatAiRequest $aiRequest) {}

    public function build(): array
    {
        $conversation = $this->aiRequest->conversation;
        $student      = $conversation->student;
        $student->loadMissing('faculty');

        $messages = $conversation->messages()
            ->where('status', ChatMessage::STATUS_COMPLETED)
            ->whereIn('role', [ChatMessage::ROLE_USER, ChatMessage::ROLE_ASSISTANT])
            ->orderBy('sequence_number')
            ->get()
            ->map(fn (ChatMessage $m) => [
                'role'    => $m->role,
                'content' => $m->content,
            ])
            ->values()
            ->toArray();

        return [
            'schema_version'  => '1.0',
            'request_id'      => $this->aiRequest->uuid,
            'conversation_id' => $conversation->uuid,
            'user_reference'  => 'student:' . $student->student_id,
            'language'        => 'auto',
            'messages'        => $messages,
            'student_context' => [
                'student_id'        => $student->student_id,
                'full_name'         => $student->full_name,
                'email'             => $student->email,
                'faculty_id'        => $student->faculty_id,
                'faculty_name'      => $student->faculty?->name,
                'gpa'               => (float) $student->gpa,
                'credits_completed' => $student->credits_completed,
                'credits_required'  => $student->credits_required,
            ],
        ];
    }
}
