<?php

namespace Tests\Feature\Database;

use App\Models\Faculty;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\FacultyCreditHoursSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the official program/credit-hours upsert seeder.
 *
 * Behavior under test:
 *   - Upserts exactly the 42 official programs from CH.pdf.
 *   - Updates an existing faculty matched by name (no duplicate).
 *   - Never deletes existing custom/manual faculties.
 */
class FacultyCreditHoursSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_upserts_exactly_the_42_official_programs(): void
    {
        $this->seed(FacultyCreditHoursSeeder::class);

        $this->assertCount(42, FacultyCreditHoursSeeder::PROGRAMS);
        $this->assertDatabaseCount('faculties', 42);

        // Every official program exists with its exact sector/field/credit_hours.
        foreach (FacultyCreditHoursSeeder::PROGRAMS as [$sector, $field, $name, $creditHours]) {
            $this->assertDatabaseHas('faculties', [
                'name' => $name,
                'sector' => $sector,
                'field' => $field,
                'credit_hours' => $creditHours,
            ]);
        }

        // Spot-check the headline programs from the brief.
        $this->assertSame(211, Faculty::where('name', 'Medicine & Surgery Program')->value('credit_hours'));
        $this->assertSame(127, Faculty::where('name', 'Computer Science Program')->value('credit_hours'));
        $this->assertSame(165, Faculty::where('name', 'Computer Engineering Program')->value('credit_hours'));
    }

    public function test_seeder_updates_existing_matching_faculty_without_duplicating(): void
    {
        // A row already exists with the same name but stale/missing data.
        $existing = Faculty::create([
            'name' => 'Computer Science Program',
            'sector' => null,
            'field' => null,
            'credit_hours' => 1,
        ]);

        $this->seed(FacultyCreditHoursSeeder::class);

        // No duplicate created — same id, now updated.
        $this->assertSame(1, Faculty::where('name', 'Computer Science Program')->count());

        $fresh = $existing->fresh();
        $this->assertSame('Sciences Sector', $fresh->sector);
        $this->assertSame('Computer Science', $fresh->field);
        $this->assertSame(127, $fresh->credit_hours);

        // Total stays at 42 because the pre-existing row matched by name.
        $this->assertDatabaseCount('faculties', 42);
    }

    public function test_seeder_does_not_delete_existing_custom_faculties(): void
    {
        $custom = Faculty::factory()->create(['name' => 'My Custom Faculty']);

        $this->seed(FacultyCreditHoursSeeder::class);

        $this->assertDatabaseHas('faculties', ['id' => $custom->id, 'name' => 'My Custom Faculty']);
        // 42 official + 1 custom.
        $this->assertDatabaseCount('faculties', 43);
    }

    public function test_default_seed_creates_only_official_programs_with_credit_hours(): void
    {
        // The full default seed path (DatabaseSeeder) must NOT create legacy
        // placeholder rows (null sector/field/credit_hours) — only the 42
        // official programs. AdminSeeder requires ADMIN_PASSWORD, so provide it.
        $previous = getenv('ADMIN_PASSWORD');
        putenv('ADMIN_PASSWORD=TestSeederPass1!');
        $_ENV['ADMIN_PASSWORD'] = 'TestSeederPass1!';

        try {
            $this->seed(DatabaseSeeder::class);
        } finally {
            if ($previous === false) {
                putenv('ADMIN_PASSWORD');
                unset($_ENV['ADMIN_PASSWORD']);
            } else {
                putenv('ADMIN_PASSWORD='.$previous);
                $_ENV['ADMIN_PASSWORD'] = $previous;
            }
        }

        $this->assertSame(42, Faculty::count());
        $this->assertSame(0, Faculty::whereNull('credit_hours')->count());
        $this->assertSame(0, Faculty::whereNull('sector')->count());
        $this->assertSame(0, Faculty::whereNull('field')->count());
    }
}
