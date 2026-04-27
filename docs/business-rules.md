# Business Rules

## 1. Authentication

### Student Authentication
- Students **cannot self-register**. All student accounts are created by `super_admin` or `academic_admin` through the Filament dashboard.
- Student login uses `student_id` and `password` (not email).
- Student API authentication uses **Laravel Sanctum Bearer tokens**.
- Tokens are created on login and revoked on logout.
- Protected API endpoints require a valid Bearer token belonging to an `App\Models\Student` instance.
- If a token does not belong to a Student, the API returns **403 Forbidden**.

### Admin Authentication
- Admins log in through the **Filament dashboard only** at `/admin`.
- Admin login uses `email` and `password`.
- Admin authentication is **session-based** (not API tokens).
- There is **no admin API login** endpoint.

### OTP / Email Verification
- OTP and email verification are **not implemented**. This is postponed for a future phase.

---

## 2. Student Profile

The student profile/dashboard displays **only** these fields:
- `full_name`
- `student_id`
- `email`
- `faculty` (id and name)
- `gpa`
- `credits_completed`
- `credits_required`

No additional student fields exist in the current implementation.

---

## 3. Admin Roles

| Role | Description |
|------|-------------|
| `super_admin` | Full access to all resources. Can manage admins, faculties, students, and vehicle requests. |
| `academic_admin` | Can manage faculties and students. Cannot access admins or vehicle requests. |
| `vehicle_admin` | Can manage vehicle requests (approve/reject). Cannot access admins, faculties, or students. |
| `support_admin` | Reserved for a future support ticket module. Can log in but has no active resources. |

### Access Enforcement
- Role-based access is enforced by **Laravel policies**, not just UI hiding.
- Direct URL access to unauthorized resources returns **403 Forbidden**.
- Each Filament resource checks the logged-in admin's role before allowing access.

---

## 4. Vehicle Access Request Rules

### Who Can Submit
- Only **logged-in students** can submit vehicle access requests via the API.
- Guests cannot submit vehicle requests.
- Staff vehicle access is **not implemented**.
- Admins cannot create vehicle requests through Filament.

### Submission Fields
Students must provide:
- `vehicle_type` (e.g., Car, Motorcycle)
- `vehicle_model` (e.g., Toyota Corolla)
- `vehicle_color` (e.g., White)
- `plate_number` (e.g., ABC123)

### Request Status Lifecycle

```
[Student submits] → pending → [Admin reviews] → approved OR rejected
```

| Status | Meaning |
|--------|---------|
| `pending` | Request submitted, awaiting admin review |
| `approved` | Approved by admin, valid for one semester |
| `rejected` | Rejected by admin with a stated reason |

### Submission Blocking Rules

| Condition | Can Submit? |
|-----------|:-----------:|
| Student has a **pending** request | ❌ No (422) |
| Student has an **active approved** permit (today is between semester dates) | ❌ No (422) |
| Student's latest request was **rejected** | ✅ Yes |
| Student's previous approved permit has **expired** | ✅ Yes |
| Student has **no** previous requests | ✅ Yes |

> **Important**: Only *currently active* approved permits (where today falls between `semester_start_date` and `semester_end_date`) block new submissions. Future-dated approved permits do not block.

### Vehicle State API Logic

The `GET /api/v1/student/vehicle` endpoint returns one of these states:

| Condition | Returned Status | Data |
|-----------|-----------------|------|
| No request exists | `none` | `null` |
| Latest request is pending | `pending` | Vehicle details + submitted_at |
| Latest is approved AND today is within semester dates | `approved` | Vehicle details + dates |
| Latest is approved BUT permit expired | `none` | `null` |
| Latest request is rejected | `rejected` | Vehicle details + rejection_reason |

### Approval Process
- Performed by `super_admin` or `vehicle_admin` in Filament.
- Admin must provide `semester_start_date` and `semester_end_date`.
- The system checks that the student does not already have an active approved permit.
- On approval: status changes to `approved`, reviewing admin is recorded, dates are set.

### Rejection Process
- Performed by `super_admin` or `vehicle_admin` in Filament.
- Admin must provide a `rejection_reason`.
- On rejection: status changes to `rejected`, reviewing admin is recorded, reason is saved.

### Permit Validity
- An approved permit is valid for **one semester**.
- Validity is determined by `semester_start_date` and `semester_end_date`.
- Once `semester_end_date` passes, the permit is considered expired.
- The student can then submit a new vehicle request.

---

## 5. Protected Root Admin Account

The account `admin@galala.edu.eg` is the **permanent root super admin**.

| Rule | Enforcement |
|------|-------------|
| Cannot be deleted | Policy `delete()` returns false + UI button hidden |
| Cannot have role changed | Form field disabled + `mutateFormDataBeforeSave()` forces `super_admin` |
| Cannot have email changed | Form field disabled + `mutateFormDataBeforeSave()` forces email |
| Cannot be bulk deleted | Bulk delete action removed from AdminResource |
| Cannot delete own account | Policy checks `$user->id !== $model->id` |
| Name/password can be edited | Allowed for maintenance |

---

## 6. Chatbot

- A public chatbot and a logged-in student chatbot are planned features.
- These are handled by the **AI team separately**.
- The chatbot is **not implemented** in this Laravel backend.
- No chatbot tables, models, controllers, or routes exist.

---

## 7. Support Tickets

- A support ticket system is a **planned future module**.
- The `support_admin` role exists in preparation for this module.
- No support ticket tables, models, controllers, or routes exist in the current implementation.
