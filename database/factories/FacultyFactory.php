<?php

namespace Database\Factories;

use App\Models\Faculty;
use Illuminate\Database\Eloquent\Factories\Factory;

class FacultyFactory extends Factory
{
    protected $model = Faculty::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->word.' Program',
            'sector' => $this->faker->randomElement([
                'Healthcare Sector',
                'Sciences Sector',
                'Engineering Sector',
                'Humanities Sector',
                'Creative Arts Sector',
            ]),
            'field' => $this->faker->word().' Field',
            'credit_hours' => $this->faker->numberBetween(120, 230),
        ];
    }
}
