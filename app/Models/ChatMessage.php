<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ChatMessage extends Model
{
    use HasFactory;

    // ──────────────────────────────────────────────
    // Constants
    // ──────────────────────────────────────────────

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_SYSTEM = 'system';

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    // ──────────────────────────────────────────────
    // Mass assignment
    // ──────────────────────────────────────────────

    protected $fillable = [
        'uuid',
        'chat_conversation_id',
        'role',
        'content',
        'status',
        'sequence_number',
        'client_message_id',
    ];

    // ──────────────────────────────────────────────
    // UUID auto-generation
    // ──────────────────────────────────────────────

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    // ──────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'chat_conversation_id');
    }

    /**
     * HasMany — retries create multiple AI-request attempts for the same user message.
     */
    public function aiRequestsAsUser(): HasMany
    {
        return $this->hasMany(ChatAiRequest::class, 'user_message_id');
    }

    /**
     * HasMany — retries create multiple AI-request attempts for the same assistant placeholder.
     */
    public function aiRequestsAsAssistant(): HasMany
    {
        return $this->hasMany(ChatAiRequest::class, 'assistant_message_id');
    }
}
