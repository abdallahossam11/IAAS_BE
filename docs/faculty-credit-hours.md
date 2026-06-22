# Faculty / Program Credit Hours

## Why `faculties` now stores sector / field / credit hours

The `faculties` table is **not renamed** (students still reference `faculty_id`),
but each row now represents a selectable academic **program** rather than a bare
faculty name. Every program carries its fixed graduation credit-hours
requirement, e.g.:

- Medicine & Surgery Program — 211 credit hours
- Computer Science Program — 127 credit hours
- Computer Engineering Program — 165 credit hours

When an admin assigns a student to a program, the student's `credits_required`
is automatically set from that program's `credit_hours`.

No separate `programs` table was created — the existing `faculties` table was
extended in place (minimal, compatible change).

## Database

Migration `2026_06_22_000004_add_credit_hours_to_faculties_table` adds to
`faculties` (all **nullable** so existing production rows are not broken):

| Column         | Type                   | Notes                                |
| -------------- | ---------------------- | ------------------------------------ |
| `sector`       | `string` (nullable)    | e.g. "Healthcare Sector"             |
| `field`        | `string` (nullable)    | e.g. "Computer Science"              |
| `credit_hours` | `unsignedSmallInteger` | fixed credit-hours for the program   |

The existing `name` column (unique program name) is unchanged. On the `Faculty`
model the new columns are fillable and `credit_hours` is cast to `integer`.

## Source data (CH.pdf)

The official program list (Sector | Field | Program | Credit Hours) — **42
programs** — is encoded in
[`Database\Seeders\FacultyCreditHoursSeeder::PROGRAMS`](../database/seeders/FacultyCreditHoursSeeder.php).
Totals by sector: Healthcare 5, Sciences 14, Engineering 8, Humanities 9,
Creative Arts 6.

## Seeding

`FacultyCreditHoursSeeder` **upserts by exact program name**
(`updateOrCreate(['name' => …], [...])`):

- If a faculty row with the same name exists → updates `sector`, `field`,
  `credit_hours`.
- If not → creates it.
- It **never deletes** existing custom/manual faculties and does **not** modify
  any students.
- It is **idempotent** — safe to run repeatedly.

It is wired into `DatabaseSeeder` as the sole faculty seeder, so it runs as part
of the normal seed. The legacy `FacultySeeder` (which created broad placeholder
rows with no sector/field/credit_hours) is intentionally **not** called from
`DatabaseSeeder`, so a fresh seed contains only the 42 official programs (no
null-credit placeholder faculties). To run the upsert on its own:

```bash
php artisan db:seed --class=FacultyCreditHoursSeeder
```

## Student `credits_required` auto-fill

In the Filament **Student** create/edit form:

- The Faculty/Program select is `live()`; choosing a program auto-fills
  `credits_required` from that program's `credit_hours`.
- `credits_required` is **read-only** in the form (cannot be hand-edited) but is
  still submitted.
- **Server-side enforcement is the source of truth.** On both create and edit
  save, `CreateStudent` / `EditStudent` set
  `credits_required = Faculty::find($faculty_id)->credit_hours`, ignoring any
  tampered submitted value. This holds even if the live front-end auto-fill is
  bypassed.
- **Legacy fallback:** if the selected faculty has a `null` `credit_hours`
  (legacy row not yet backfilled), the submitted `credits_required` is kept as-is
  (no crash). New faculties created via the dashboard always require
  `credit_hours`, so this only affects un-backfilled legacy rows.

## Profile API additions

`GET /api/v1/student/profile` — the `data.faculty` object gains three fields
(existing fields unchanged):

```json
{
  "data": {
    "faculty": {
      "id": 1,
      "name": "Computer Science Program",
      "sector": "Sciences Sector",
      "field": "Computer Science",
      "credit_hours": 127
    },
    "credits_required": 140
  }
}
```

`data.credits_required` remains the **student's own snapshot** and is independent
of the live `faculty.credit_hours` (a student keeps their assigned requirement
even if a program's credit hours are later changed).

## Production deployment notes

1. Deploy code, then run the additive migration:
   ```bash
   php artisan migrate
   ```
   (The new columns are nullable — no downtime, no data loss.)
2. Backfill/refresh the official programs:
   ```bash
   php artisan db:seed --class=FacultyCreditHoursSeeder
   ```
   This upserts the 42 programs and leaves any custom faculties untouched.
3. Existing students are **not** modified by the migration or seeder. Their
   `credits_required` is only recomputed when an admin next saves them in the
   dashboard (or you can backfill separately if desired).
4. **Do NOT run `migrate:fresh` on production** — it drops all tables. Use
   `migrate` only.

## Faculty dashboard

The Filament **Faculty** resource form now requires `sector`, `field`,
`name` (program name), and `credit_hours` (integer 1–300). The table shows
sector, field, program name, credit hours, and the students count. Existing
delete protections are unchanged: a faculty whose students have chat history
cannot be deleted (single delete hidden; bulk delete skips it with a warning).
