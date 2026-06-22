<?php

namespace Database\Factories;

use App\Models\Faculty;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

class StudentFactory extends Factory
{
    protected $model = Student::class;

    public function definition(): array
    {
        // Keep the student's credits_required in sync with the related
        // faculty/program credit_hours so generated data mirrors the
        // server-side enforcement done in the Filament create/edit hooks.
        $creditHours = $this->faker->numberBetween(120, 230);

        return [
            'student_id' => $this->faker->unique()->numerify('202#####'),
            'password' => bcrypt('password123'),
            // Default factory students are treated as already onboarded (they
            // have set their own password) so they can exercise protected
            // endpoints directly. Use the mustChangePassword() state to model a
            // freshly admin-created account on a temporary password.
            'password_must_be_changed' => false,
            'password_changed_at' => now(),
            'full_name' => $this->faker->name,
            'email' => $this->faker->unique()->safeEmail,
            // Realistic university-age date of birth, stored as a date only.
            'date_of_birth' => $this->faker->dateTimeBetween('-30 years', '-18 years')->format('Y-m-d'),
            'faculty_id' => Faculty::factory()->state(['credit_hours' => $creditHours]),
            'gpa' => $this->faker->randomFloat(2, 2.0, 4.0),
            'credits_completed' => $this->faker->numberBetween(0, 120),
            'credits_required' => $creditHours,
        ];
    }

    /**
     * A freshly admin-created student still on a temporary password — they must
     * change it before normal student features unlock.
     */
    public function mustChangePassword(): static
    {
        return $this->state(fn (array $attributes): array => [
            'password_must_be_changed' => true,
            'password_changed_at' => null,
        ]);
    }
}
