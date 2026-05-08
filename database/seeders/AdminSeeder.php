<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('ADMIN_PASSWORD');
        if (empty($password)) {
            throw new \Exception('ADMIN_PASSWORD must be set in the environment variables to seed the admin user.');
        }

        Admin::create([
            'name' => env('ADMIN_NAME', 'Super Admin'),
            'email' => env('ADMIN_EMAIL', 'admin@galala.edu.eg'),
            'password' => $password,
            'role' => 'super_admin',
        ]);
    }
}
