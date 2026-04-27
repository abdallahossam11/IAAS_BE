<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Admin extends Authenticatable implements FilamentUser
{
    use HasFactory, Notifiable;

    protected $table = 'admins';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    // ──────────────────────────────────────────────
    // Filament access
    // ──────────────────────────────────────────────

    public function canAccessPanel(Panel $panel): bool
    {
        // All admin roles can access the Filament admin panel.
        // Per-resource access is controlled by policies.
        return true;
    }

    // ──────────────────────────────────────────────
    // Role helpers
    // ──────────────────────────────────────────────

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function isVehicleAdmin(): bool
    {
        return $this->role === 'vehicle_admin';
    }

    public function isAcademicAdmin(): bool
    {
        return $this->role === 'academic_admin';
    }

    public function isSupportAdmin(): bool
    {
        return $this->role === 'support_admin';
    }

    // ──────────────────────────────────────────────
    // Relationships (will be used in later phases)
    // ──────────────────────────────────────────────

    public function reviewedVehicleRequests(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\VehicleRequest::class, 'admin_id');
    }
}
