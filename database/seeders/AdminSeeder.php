<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        Admin::create([
            'name' => 'Super Admin',
            'email' => 'admin@galala.edu.eg',
            'password' => 'password123', // Hashed automatically by the model's 'hashed' cast
            'role' => 'super_admin',
        ]);
    }
}
