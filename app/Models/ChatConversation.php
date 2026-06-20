<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class ChatConversation extends Model
{
    use HasFactory;

    // ──────────────────────────────────────────────
    // Constants
    // ──────────────────────────────────────────────

    public const STATUS_ACTIVE = 'active';

    // ──────────────────────────────────────────────
    // Mass assignment
    // ──────────────────────────────────────────────

    protected $fillable = [
        'uuid',
        'session_id',
        'student_id',
        'title',
        'status',
        'last_message_at',
        'deleted_by_student_at',
        'summary_updated_at',
    ];

    // ──────────────────────────────────────────────
    // Casts
    // ──────────────────────────────────────────────

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'deleted_by_student_at' => 'datetime',
            'summary_updated_at' => 'datetime',
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
    // Scopes
    // ──────────────────────────────────────────────

    public function scopeVisibleToStudent(Builder $query): Builder
    {
        return $query->whereNull('deleted_by_student_at');
    }

    // ──────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'chat_conversation_id');
    }

    public function aiRequests(): HasMany
    {
        return $this->hasMany(ChatAiRequest::class, 'chat_conversation_id');
    }

    /**
     * The AI-owned summary row for this conversation (keyed by session_id).
     * Null until the AI service has responded at least once AND a summarization
     * job has run. The AI service writes chat_summaries directly; the backend
     * sets summary_updated_at on this model after each successful summarize call.
     */
    public function chatSummary(): HasOne
    {
        return $this->hasOne(ChatSummary::class, 'session_id', 'session_id');
    }
}
