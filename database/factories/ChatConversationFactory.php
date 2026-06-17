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
            'session_id' => null, // AI-generated; null until the AI responds
            'student_id' => Student::factory(),
            'title'      => $this->faker->sentence(4),
            'status'     => ChatConversation::STATUS_ACTIVE,
            'last_message_at'       => null,
            'deleted_by_student_at' => null,
        ];
    }

    /**
     * A conversation that already has an AI session id (i.e. the AI has
     * responded at least once).
     */
    public function withSession(?string $sessionId = null): static
    {
        return $this->state(fn () => [
            'session_id' => $sessionId ?? 'sess-' . Str::uuid()->toString(),
        ]);
    }
}
