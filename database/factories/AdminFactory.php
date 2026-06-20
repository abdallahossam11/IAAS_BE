<?php

namespace Database\Factories;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Factories\Factory;

class AdminFactory extends Factory
{
    protected $model = Admin::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => bcrypt('password'),
            'role' => 'vehicle_admin',
        ];
    }

    public function superAdmin(): static
    {
        return $this->state(['role' => 'super_admin']);
    }

    public function vehicleAdmin(): static
    {
        return $this->state(['role' => 'vehicle_admin']);
    }

    public function supportAdmin(): static
    {
        return $this->state(['role' => 'support_admin']);
    }

    public function academicAdmin(): static
    {
        return $this->state(['role' => 'academic_admin']);
    }
}
