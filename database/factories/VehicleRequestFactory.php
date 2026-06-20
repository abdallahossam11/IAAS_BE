<?php

namespace Database\Factories;

use App\Models\Admin;
use App\Models\Student;
use App\Models\VehicleRequest;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

class VehicleRequestFactory extends Factory
{
    protected $model = VehicleRequest::class;

    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'vehicle_type' => $this->faker->randomElement(['Car', 'Motorcycle', 'Van']),
            'vehicle_model' => $this->faker->word().' '.$this->faker->year(),
            'vehicle_color' => $this->faker->colorName(),
            'plate_number' => strtoupper($this->faker->bothify('???-####')),
            'status' => 'pending',
            'admin_id' => null,
            'rejection_reason' => null,
            'approved_at' => null,
            'semester_start_date' => null,
            'semester_end_date' => null,
        ];
    }

    /** Pending: all nullable date fields are null (the crash scenario). */
    public function pending(): static
    {
        return $this->state([
            'status' => 'pending',
            'admin_id' => null,
            'rejection_reason' => null,
            'approved_at' => null,
            'semester_start_date' => null,
            'semester_end_date' => null,
        ]);
    }

    /** Rejected: date fields explicitly null, rejection_reason set. */
    public function rejected(?Admin $admin = null): static
    {
        return $this->state([
            'status' => 'rejected',
            'admin_id' => $admin?->id ?? Admin::factory(),
            'rejection_reason' => $this->faker->sentence(),
            'approved_at' => null,
            'semester_start_date' => null,
            'semester_end_date' => null,
        ]);
    }

    /** Approved: all date fields populated with real values. */
    public function approved(?Admin $admin = null): static
    {
        $start = Carbon::now()->subDays(10)->startOfDay();

        return $this->state([
            'status' => 'approved',
            'admin_id' => $admin?->id ?? Admin::factory(),
            'rejection_reason' => null,
            'approved_at' => Carbon::now()->subDays(10),
            'semester_start_date' => $start,
            'semester_end_date' => $start->copy()->addMonths(4),
        ]);
    }
}
