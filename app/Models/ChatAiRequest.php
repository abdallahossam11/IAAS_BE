<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ChatAiRequest extends Model
{
    use HasFactory;

    // ──────────────────────────────────────────────
    // Constants
    // ──────────────────────────────────────────────

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    // ──────────────────────────────────────────────
    // Mass assignment
    // ──────────────────────────────────────────────

    protected $fillable = [
        'uuid',
        'chat_conversation_id',
        'user_message_id',
        'assistant_message_id',
        'status',
        'attempt_number',
        'error_code',
        'error_message',
        'submitted_at',
        'completed_at',
        'failed_at',
    ];

    // ──────────────────────────────────────────────
    // Casts
    // ──────────────────────────────────────────────

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

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

    public function userMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'user_message_id');
    }

    public function assistantMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'assistant_message_id');
    }
}
