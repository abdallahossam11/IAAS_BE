<?php

namespace Database\Factories;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ChatMessageFactory extends Factory
{
    protected $model = ChatMessage::class;

    /**
     * Default state: a completed user message.
     * Factory callers can override any attribute.
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'chat_conversation_id' => ChatConversation::factory(),
            'role' => ChatMessage::ROLE_USER,
            'content' => $this->faker->sentence(),
            'status' => ChatMessage::STATUS_COMPLETED,
            'sequence_number' => 1,
            'client_message_id' => (string) Str::uuid(),
        ];
    }

    // ──────────────────────────────────────────────
    // States
    // ──────────────────────────────────────────────

    /**
     * A pending assistant placeholder: no content, no client_message_id.
     */
    public function pendingAssistant(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => ChatMessage::ROLE_ASSISTANT,
            'content' => null,
            'status' => ChatMessage::STATUS_PENDING,
            'client_message_id' => null,
        ]);
    }

    /**
     * A failed assistant placeholder: no content, no client_message_id.
     */
    public function failedAssistant(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => ChatMessage::ROLE_ASSISTANT,
            'content' => null,
            'status' => ChatMessage::STATUS_FAILED,
            'client_message_id' => null,
        ]);
    }
}
