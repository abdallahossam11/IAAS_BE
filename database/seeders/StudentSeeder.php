<?php

namespace Database\Seeders;

use App\Models\Faculty;
use App\Models\Student;
use Illuminate\Database\Seeder;

class StudentSeeder extends Seeder
{
    public function run(): void
    {
        $engineering = Faculty::where('name', 'Engineering')->first();

        Student::create([
            'student_id' => '20230001',
            'full_name' => 'Ahmed Mohamed',
            'email' => 'student@galala.edu.eg',
            'password' => 'password123', // Hashed automatically by the model's 'hashed' cast
            'faculty_id' => $engineering->id,
            'gpa' => 3.20,
            'credits_completed' => 90,
            'credits_required' => 144,
        ]);
    }
}
