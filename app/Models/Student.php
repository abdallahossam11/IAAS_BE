<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Student extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $fillable = [
        'student_id',
        'full_name',
        'email',
        'password',
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
            'gpa' => 'decimal:2',
            'credits_completed' => 'integer',
            'credits_required' => 'integer',
        ];
    }

    // ──────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────

    public function faculty(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Faculty::class);
    }

    public function vehicleRequests(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(VehicleRequest::class, 'student_id');
    }

    public function chatConversations(): \Illuminate\Database\Eloquent\Relations\HasMany
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
