<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
        'student_id',
        'title',
        'status',
        'last_message_at',
        'deleted_by_student_at',
    ];

    // ──────────────────────────────────────────────
    // Casts
    // ──────────────────────────────────────────────

    protected function casts(): array
    {
        return [
            'last_message_at'       => 'datetime',
            'deleted_by_student_at' => 'datetime',
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

    public function student(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function messages(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ChatMessage::class, 'chat_conversation_id');
    }

    public function aiRequests(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ChatAiRequest::class, 'chat_conversation_id');
    }
}
