# Student Password Flows

Covers two related student-account flows:

1. **First-login / temporary-password enforcement** — students created by an
   admin start on a temporary password and must set their own before they can
   use normal features.
2. **Forgot-password via emailed OTP** — students who forget their password can
   reset it with a one-time code emailed to them, and are logged in afterwards.

All routes live under the existing student API prefix `/api/v1/student`.

---

## 1. First-login flow

### Data model

`students` table gains two columns (migration
`2026_06_22_000001_add_password_change_flags_to_students_table`):

| Column                     | Type                | Default | Meaning                                                        |
| -------------------------- | ------------------- | ------- | -------------------------------------------------------------- |
| `password_must_be_changed` | `boolean`           | `false` | `true` while the account is on an admin-issued temp password.  |
| `password_changed_at`      | `timestamp` (null)  | `null`  | Set to `now()` on the last student-initiated password change.  |

On the `Student` model these are cast to `boolean` and `datetime` respectively.

### When the flag is set to `true`

| Path                                         | Where                                                            |
| -------------------------------------------- | --------------------------------------------------------------- |
| Admin creates a student (Filament dashboard) | `CreateStudent::mutateFormDataBeforeCreate()`                   |
| Admin resets a password (Filament edit form) | `EditStudent::mutateFormDataBeforeSave()` (only when a new password is entered) |

> There is **no dedicated API or Filament "reset password" button**. The admin
> "reset/regenerate" path is the optional password field on the **Edit Student**
> form — entering a new value there re-arms the must-change gate. This is the
> only admin password-reset path in the project.

There is no CSV/bulk-import path in the codebase. The `Student` factory defaults
to `password_must_be_changed = false` (an already-onboarded student) so existing
tests can exercise protected endpoints directly; use the
`Student::factory()->mustChangePassword()` state to model a freshly admin-created
account.

### When the flag is cleared

The flag is set back to `false` (and `password_changed_at = now()`) when the
student successfully:

- changes their password via `POST /api/v1/student/change-password`, or
- resets it via `POST /api/v1/student/forgot-password/reset`.

### Where the flag is surfaced

- `POST /api/v1/student/login/verify-otp` → `data.must_change_password` and
  `data.student.must_change_password`.
- `GET  /api/v1/student/profile` → `data.must_change_password`.
- `POST /api/v1/student/change-password` → `data.must_change_password` (`false`).
- `POST /api/v1/student/forgot-password/reset` → `data.must_change_password`
  (`false`) and `data.student.must_change_password`.

### Gate: `409 PASSWORD_CHANGE_REQUIRED`

The `EnsurePasswordChanged` middleware (alias `ensure.password_changed`) blocks
business endpoints while `password_must_be_changed` is `true`.

**Blocked** (return `409`):

- `GET  /api/v1/student/vehicle`
- `POST /api/v1/student/vehicle-requests`
- `GET  /api/v1/student/vehicle-requests/history`
- all `…/chats…` endpoints

**Never blocked:** login, OTP verify, logout, change-password, the two
forgot-password endpoints, and `GET /profile` (so the frontend can read the
flag).

`409` response body (stable contract):

```json
{
  "message": "Password change is required before continuing.",
  "code": "PASSWORD_CHANGE_REQUIRED",
  "must_change_password": true
}
```

---

## 2. Forgot-password flow

```
Click "forgot password"
  → POST /forgot-password { email }            (OTP emailed)
  → enter OTP + new password
  → POST /forgot-password/reset { email, otp_code, password, password_confirmation }
  → student is logged in (fresh Sanctum token returned)
```

### OTP storage

Reuses the existing `student_login_otps` table and its hashing approach
(`otp_hash` via bcrypt, challenge token via SHA-256). A new `purpose` column
(migration `2026_06_22_000002_add_purpose_to_student_login_otps_table`,
default `'login'`) separates intents:

- `login` — issued by the two-step login flow.
- `password_reset` — issued by the forgot-password flow.

Login verification is scoped to `purpose = login` and password reset is scoped to
`purpose = password_reset`, so **a login OTP can never reset a password and a
reset OTP can never log in.**

The forgot-password flow is keyed by **email + code** (no client-facing challenge
token), so the request response cannot leak account existence via a token.

---

## Endpoint list

| Method | Path                                  | Auth        | Throttle  | Notes                                  |
| ------ | ------------------------------------- | ----------- | --------- | -------------------------------------- |
| POST   | `/api/v1/student/forgot-password`       | public      | `5,1`     | Always returns a generic success.      |
| POST   | `/api/v1/student/forgot-password/reset` | public      | `10,1`    | Verifies OTP, resets password, logs in.|
| POST   | `/api/v1/student/change-password`       | sanctum     | —         | Authenticated change; clears the flag. |

(Existing endpoints — `/login`, `/login/verify-otp`, `/logout`, `/profile`,
`/vehicle*`, `/chats*` — are unchanged in shape except for the added
`must_change_password` fields and the `409` gate described above.)

---

## Request / response examples

### `POST /api/v1/student/forgot-password`

Request:

```json
{ "email": "student@example.com" }
```

Response `200` (identical whether or not the email exists):

```json
{
  "success": true,
  "message": "If an account exists for that email, a password reset code has been sent."
}
```

### `POST /api/v1/student/forgot-password/reset`

Request:

```json
{
  "email": "student@example.com",
  "otp_code": "123456",
  "password": "NewSecurePa55!",
  "password_confirmation": "NewSecurePa55!"
}
```

Response `200`:

```json
{
  "success": true,
  "message": "Password reset successfully.",
  "data": {
    "token": "12|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
    "must_change_password": false,
    "student": {
      "id": 1,
      "student_id": "GU-20240001",
      "full_name": "Ahmed Hassan",
      "email": "student@example.com",
      "must_change_password": false
    }
  }
}
```

Failure (invalid / expired / reused OTP, or unknown email) → `422`:

```json
{ "success": false, "message": "Invalid or expired verification code." }
```

Too many wrong attempts on a single OTP → `429`.

### `POST /api/v1/student/login/verify-otp` (relevant additions)

```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "token": "10|xxxxxxxx",
    "must_change_password": true,
    "student": {
      "id": 1,
      "student_id": "GU-20240001",
      "full_name": "Ahmed Hassan",
      "email": "student@example.com",
      "must_change_password": true
    }
  }
}
```

### `POST /api/v1/student/change-password`

Request:

```json
{
  "current_password": "TempPa55!",
  "new_password": "NewSecurePa55!",
  "new_password_confirmation": "NewSecurePa55!"
}
```

Response `200`:

```json
{
  "success": true,
  "message": "Password changed successfully.",
  "data": { "must_change_password": false }
}
```

---

## Frontend integration notes

1. After OTP verify (or forgot-password reset), read
   `data.must_change_password`. If `true`, route the user straight to the
   change-password screen.
2. If any business call returns **HTTP `409`** with
   `code = "PASSWORD_CHANGE_REQUIRED"`, force the change-password screen and
   retry once the password has been changed (the existing token stays valid —
   no re-login needed after `change-password`).
3. `GET /profile` is always reachable and returns `must_change_password`, so it
   can be used on app start to decide whether to show the change-password gate.
4. Forgot-password is a two-screen flow: collect the email, then collect the
   emailed OTP + new password. The frontend already holds the email from the
   first screen, so the reset call needs `email`, `otp_code`, `password`,
   `password_confirmation`. On success the returned `data.token` logs the user
   in immediately — store it and drop any previous token (old tokens are
   revoked server-side).
5. Treat the forgot-password request response as informational only — it never
   reveals whether the email exists.

---

## Security notes

- **No account enumeration on forgot-password**: the request endpoint returns the
  same generic message regardless of whether the email belongs to a student, and
  the reset endpoint returns the same `422` for an unknown email as for a bad
  OTP. (The existing two-step login does reveal invalid credentials; that
  behaviour is unchanged.)
- **OTPs are never returned** in any API response and are **never logged** —
  only audit metadata (student id, ip, result) is recorded.
- **Hashing**: reset OTP codes are bcrypt-hashed (`otp_hash`), matching the
  existing login OTP storage. Plaintext OTPs are never persisted.
- **Expiry**: reset OTPs expire after 10 minutes (same as login OTPs).
- **Single-use & rotation**: requesting a new reset OTP invalidates older unused
  reset OTPs for that student; a used OTP cannot be replayed.
- **Brute-force protection**: max 5 verification attempts per OTP (then `429`),
  plus route throttling (`5,1` on request, `10,1` on reset).
- **Cross-flow isolation**: the `purpose` column guarantees a login OTP cannot be
  used to reset a password and vice-versa.
- **Token rotation on reset**: a successful reset revokes all of the student's
  existing Sanctum tokens before issuing a new one.
