<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            // Seeds the 42 official academic programs (sector/field/credit_hours)
            // by exact program name. This is the source of truth for faculties —
            // the legacy FacultySeeder (broad null-credit placeholder rows) is
            // intentionally NOT run here. Idempotent (upsert by name).
            FacultyCreditHoursSeeder::class,
            AdminSeeder::class,
        ]);
    }
}
