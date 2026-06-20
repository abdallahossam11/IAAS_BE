<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Faculty extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
    ];

    // ──────────────────────────────────────────────
    // Relationships (will be expanded in Phase 3)
    // ──────────────────────────────────────────────

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    // ──────────────────────────────────────────────
    // Guards
    // ──────────────────────────────────────────────

    /**
     * True when at least one of the faculty's students has chatbot history.
     * Deleting such a faculty would cascade into students, but the DB's
     * restrictOnDelete on chat_conversations.student_id would then block
     * those student deletions and throw a QueryException.
     */
    public function hasStudentsWithChatHistory(): bool
    {
        return $this->students()->whereHas('chatConversations')->exists();
    }
}
