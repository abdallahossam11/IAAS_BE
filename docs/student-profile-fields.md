# Student Profile Fields

Reference for student profile attributes exposed by the API and the Filament
admin dashboard.

## Date of Birth

### Database

Migration `2026_06_22_000003_add_date_of_birth_to_students_table` adds:

| Column          | Type             | Nullable | Notes                                          |
| --------------- | ---------------- | -------- | ---------------------------------------------- |
| `date_of_birth` | `date`           | yes      | Date only (no time). Nullable for legacy rows. |

It is **nullable** on purpose: existing production students predate this field,
so the migration must not fail or require a backfill. Such rows keep
`date_of_birth = null` until updated.

On the `Student` model the column is in `$fillable` and cast to `date`.

### API response — `GET /api/v1/student/profile`

A new field is added to `data` (no existing fields changed):

| Field                | Type            | Format         |
| -------------------- | --------------- | -------------- |
| `data.date_of_birth` | `string`/`null` | `YYYY-MM-DD`   |

- Returns `YYYY-MM-DD` (e.g. `"2000-05-15"`) when set.
- Returns `null` when the student has no recorded date of birth.

Example:

```json
{
  "success": true,
  "data": {
    "full_name": "Ahmed Hassan",
    "student_id": "GU-20240001",
    "email": "ahmed@example.com",
    "date_of_birth": "2000-05-15",
    "must_change_password": false,
    "faculty": { "id": 1, "name": "Engineering" },
    "gpa": 3.5,
    "credits_completed": 60,
    "credits_required": 140
  }
}
```

### Filament admin dashboard

- **Form**: a `Date of Birth` `DatePicker`. Required when creating a new
  (admin-created) student; optional on edit so legacy students with a null DOB
  can still be saved. It cannot be a future date (`maxDate(today())`), and
  displays as `d M Y`.
- **Table**: a sortable `Date of Birth` column rendered with `->date()`,
  hidden by default (toggleable) to keep the list uncluttered.

### Validation rules

`date_of_birth` must be:

- a valid date, and
- not in the future (today or earlier).

Age calculation is intentionally **not** implemented in this phase.

### Frontend notes

- Expect `data.date_of_birth` as `YYYY-MM-DD` or `null`.
- Render `null` as "not provided" / an empty field; do not assume every student
  has a date of birth yet.
