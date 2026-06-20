<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentLoginOtp extends Model
{
    protected $fillable = [
        'student_id',
        'challenge_token_hash',
        'otp_hash',
        'attempts',
        'expires_at',
        'used_at',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function hasExceededAttempts(int $max = 5): bool
    {
        // Strictly greater-than so that MAX_ATTEMPTS=5 allows exactly 5
        // code-checking attempts before the OTP is locked out on attempt 6.
        return $this->attempts > $max;
    }
}
