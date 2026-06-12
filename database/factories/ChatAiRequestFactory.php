<?php

namespace Database\Factories;

use App\Models\ChatAiRequest;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ChatAiRequestFactory extends Factory
{
    protected $model = ChatAiRequest::class;

    /**
     * Default definition.
     *
     * FK columns are intentionally null.  The factory must not silently
     * create related database records.  Callers must supply the required
     * IDs either via explicit attribute overrides or via the forCycle()
     * state helper.
     */
    public function definition(): array
    {
        return [
            'uuid'                 => (string) Str::uuid(),
            'chat_conversation_id' => null,
            'user_message_id'      => null,
            'assistant_message_id' => null,
            'status'               => ChatAiRequest::STATUS_QUEUED,
            'attempt_number'       => 1,
            'error_code'           => null,
            'error_message'        => null,
            'submitted_at'         => null,
            'completed_at'         => null,
            'failed_at'            => null,
        ];
    }

    // ──────────────────────────────────────────────
    // Cycle state — links explicitly supplied existing records
    // ──────────────────────────────────────────────

    /**
     * Bind an AI request to a pre-created conversation turn (user message +
     * assistant placeholder).  Does not create any database records itself.
     */
    public function forCycle(
        ChatConversation $conversation,
        ChatMessage $userMessage,
        ChatMessage $assistantMessage,
    ): static {
        return $this->state(fn () => [
            'chat_conversation_id' => $conversation->id,
            'user_message_id'      => $userMessage->id,
            'assistant_message_id' => $assistantMessage->id,
        ]);
    }

    // ──────────────────────────────────────────────
    // Status states
    // ──────────────────────────────────────────────

    public function queued(): static
    {
        return $this->state(fn (array $attributes) => [
            'status'       => ChatAiRequest::STATUS_QUEUED,
            'submitted_at' => null,
            'completed_at' => null,
            'failed_at'    => null,
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status'       => ChatAiRequest::STATUS_PROCESSING,
            'submitted_at' => now(),
            'completed_at' => null,
            'failed_at'    => null,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status'       => ChatAiRequest::STATUS_COMPLETED,
            'submitted_at' => now()->subSeconds(30),
            'completed_at' => now(),
            'failed_at'    => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status'        => ChatAiRequest::STATUS_FAILED,
            'submitted_at'  => now()->subSeconds(30),
            'completed_at'  => null,
            'failed_at'     => now(),
            'error_code'    => 'AI_TIMEOUT',
            'error_message' => 'AI API did not respond in time.',
        ]);
    }
}
