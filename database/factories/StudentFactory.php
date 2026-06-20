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
        return [
            'student_id' => $this->faker->unique()->numerify('202#####'),
            'password' => bcrypt('password123'),
            'full_name' => $this->faker->name,
            'email' => $this->faker->unique()->safeEmail,
            'faculty_id' => Faculty::factory(),
            'gpa' => $this->faker->randomFloat(2, 2.0, 4.0),
            'credits_completed' => $this->faker->numberBetween(0, 120),
            'credits_required' => 140,
        ];
    }
}
