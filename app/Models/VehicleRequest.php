<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VehicleRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'vehicle_type',
        'vehicle_model',
        'vehicle_color',
        'plate_number',
        'status',
        'admin_id',
        'rejection_reason',
        'approved_at',
        'semester_start_date',
        'semester_end_date',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'semester_start_date' => 'date',
            'semester_end_date' => 'date',
        ];
    }

    // ──────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────

    public function student(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function admin(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }
}
