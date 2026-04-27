<?php

namespace Database\Seeders;

use App\Models\Faculty;
use Illuminate\Database\Seeder;

class FacultySeeder extends Seeder
{
    public function run(): void
    {
        $faculties = [
            'Engineering',
            'Computer Science',
            'Business',
            'Medicine',
            'Dentistry',
            'Pharmacy',
            'Nursing',
            'Art and Design',
            'Administrative Sciences',
        ];

        foreach ($faculties as $name) {
            Faculty::create(['name' => $name]);
        }
    }
}
