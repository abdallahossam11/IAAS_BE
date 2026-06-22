<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Student extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $fillable = [
        'student_id',
        'full_name',
        'email',
        'date_of_birth',
        'password',
        'password_must_be_changed',
        'password_changed_at',
        'faculty_id',
        'gpa',
        'credits_completed',
        'credits_required',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'password_must_be_changed' => 'boolean',
            'password_changed_at' => 'datetime',
            'date_of_birth' => 'date',
            'gpa' => 'decimal:2',
            'credits_completed' => 'integer',
            'credits_required' => 'integer',
        ];
    }

    // ──────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class);
    }

    public function vehicleRequests(): HasMany
    {
        return $this->hasMany(VehicleRequest::class, 'student_id');
    }

    public function chatConversations(): HasMany
    {
        return $this->hasMany(ChatConversation::class, 'student_id');
    }

    // ──────────────────────────────────────────────
    // Guards
    // ──────────────────────────────────────────────

    /**
     * True when the student has any chatbot conversation row (active or
     * student-hidden). Such students cannot be deleted — their chat history
     * must be removed first.
     */
    public function hasChatHistory(): bool
    {
        return $this->chatConversations()->exists();
    }
}
