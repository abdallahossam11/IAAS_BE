<?php

namespace Database\Factories;

use App\Models\ChatConversation;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ChatConversationFactory extends Factory
{
    protected $model = ChatConversation::class;

    public function definition(): array
    {
        return [
            'uuid'       => (string) Str::uuid(),
            'student_id' => Student::factory(),
            'title'      => $this->faker->sentence(4),
            'status'     => ChatConversation::STATUS_ACTIVE,
            'last_message_at'       => null,
            'deleted_by_student_at' => null,
        ];
    }
}
