# Galala University IAAS — API Documentation

## 1. Overview

The **Galala University Intelligent Academic Advisory System (IAAS)** backend is a Laravel 12 API-only application. It provides:

- **Student API** — RESTful endpoints authenticated via Laravel Sanctum Bearer tokens.
- **Admin Dashboard** — Filament-based admin panel at `/admin` using session-based login (no API).

This document covers **only** the Student API endpoints.

> **Admin access**: Admins log in through the Filament dashboard at `/admin`. There is no admin API login.
> **Gate access**: Gate devices use the `X-GATE-API-KEY` header to access the `v1/gate` endpoints.

---

## 2. Base URL

| Environment | Base URL |
|-------------|----------|
| Local (XAMPP / `php artisan serve`) | `http://127.0.0.1:8000/api/v1` |

All student endpoints are prefixed with `/api/v1/student`.

---

## 3. Authentication

### How It Works

1. Student calls `POST /api/v1/student/login` with `student_id` and `password`.
2. Server returns a **Sanctum Bearer token**.
3. All subsequent protected requests must include the token in the `Authorization` header:

```
Authorization: Bearer {token}
```

4. Calling `POST /api/v1/student/logout` revokes the current token.

### Security Notes

- Tokens are tied to the `App\Models\Student` model.
- If a token does not belong to a Student (e.g., an admin token), protected endpoints return **403 Forbidden**.
- Requests without a valid token return **401 Unauthenticated**.

---

## 4. Standard Response Format

### Success

```json
{
  "success": true,
  "message": "Descriptive message",
  "data": { }
}
```

> Note: Some endpoints omit `message` or `data` when not applicable.

### Error

```json
{
  "success": false,
  "message": "Error description"
}
```

### Validation Error (422)

```json
{
  "message": "The student id field is required.",
  "errors": {
    "student_id": ["The student id field is required."]
  }
}
```

---

## 5. Endpoints

### 5.1 POST `/api/v1/student/login`

**Purpose**: Authenticate a student and receive a Bearer token.

| Property | Value |
|----------|-------|
| Auth Required | No |
| Method | `POST` |
| URL | `/api/v1/student/login` |

#### Request Headers

| Header | Value |
|--------|-------|
| Content-Type | `application/json` |
| Accept | `application/json` |

#### Request Body

```json
{
  "student_id": "YOUR_STUDENT_ID",
  "password": "YOUR_PASSWORD"
}
```

| Field | Type | Required | Rules |
|-------|------|----------|-------|
| `student_id` | string | Yes | Must match an existing student record |
| `password` | string | Yes | Must match the student's password |

> **Important**: Login uses `student_id`, **not** email.

#### Success Response — `200 OK`

```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "token": "1|BgGdP0netZEMfVhjZNM7MhOgf4BhMLWohsOi28Kcf7cda223",
    "student": {
      "id": 1,
      "student_id": "YOUR_STUDENT_ID",
      "full_name": "Ahmed Mohamed",
      "email": "student@galala.edu.eg"
    }
  }
}
```

#### Error Response — `401 Unauthorized`

```json
{
  "success": false,
  "message": "Invalid student ID or password"
}
```

#### Error Response — `422 Unprocessable Entity`

```json
{
  "message": "The student id field is required.",
  "errors": {
    "student_id": ["The student id field is required."]
  }
}
```

---

### 5.2 POST `/api/v1/student/logout`

**Purpose**: Revoke the current access token.

| Property | Value |
|----------|-------|
| Auth Required | Yes — Bearer token |
| Method | `POST` |
| URL | `/api/v1/student/logout` |

#### Request Headers

| Header | Value |
|--------|-------|
| Authorization | `Bearer {token}` |
| Accept | `application/json` |

#### Request Body

None.

#### Success Response — `200 OK`

```json
{
  "success": true,
  "message": "Logged out successfully"
}
```

#### Error Response — `401 Unauthenticated`

```json
{
  "message": "Unauthenticated."
}
```

---

### 5.3 GET `/api/v1/student/profile`

**Purpose**: Retrieve the authenticated student's profile information.

| Property | Value |
|----------|-------|
| Auth Required | Yes — Bearer token |
| Method | `GET` |
| URL | `/api/v1/student/profile` |

#### Request Headers

| Header | Value |
|--------|-------|
| Authorization | `Bearer {token}` |
| Accept | `application/json` |

#### Success Response — `200 OK`

```json
{
  "success": true,
  "data": {
    "full_name": "Ahmed Mohamed",
    "student_id": "YOUR_STUDENT_ID",
    "email": "student@galala.edu.eg",
    "faculty": {
      "id": 1,
      "name": "Engineering"
    },
    "gpa": 3.2,
    "credits_completed": 90,
    "credits_required": 144
  }
}
```

| Field | Type | Description |
|-------|------|-------------|
| `full_name` | string | Student's full name |
| `student_id` | string | University student ID |
| `email` | string | Student email address |
| `faculty.id` | integer | Faculty primary key |
| `faculty.name` | string | Faculty name |
| `gpa` | float | Grade point average (0.00 – 4.00) |
| `credits_completed` | integer | Credits completed so far |
| `credits_required` | integer | Total credits required for graduation |

---

### 5.4 GET `/api/v1/student/vehicle`

**Purpose**: Get the current vehicle access state for the authenticated student.

| Property | Value |
|----------|-------|
| Auth Required | Yes — Bearer token |
| Method | `GET` |
| URL | `/api/v1/student/vehicle` |

#### Request Headers

| Header | Value |
|--------|-------|
| Authorization | `Bearer {token}` |
| Accept | `application/json` |

#### Vehicle State Logic

| Condition | Returned Status |
|-----------|-----------------|
| No vehicle request exists | `none` |
| Latest request is pending | `pending` |
| Latest request is approved AND today is between `semester_start_date` and `semester_end_date` | `approved` |
| Latest request is approved BUT the permit has expired | `none` |
| Latest request is rejected | `rejected` |

#### Response — Status `none`

```json
{
  "success": true,
  "status": "none",
  "data": null
}
```

#### Response — Status `pending`

```json
{
  "success": true,
  "status": "pending",
  "data": {
    "id": 1,
    "vehicle_type": "Car",
    "vehicle_model": "Toyota Corolla",
    "vehicle_color": "White",
    "plate_number": "ABC123",
    "submitted_at": "2026-04-27"
  }
}
```

#### Response — Status `approved`

```json
{
  "success": true,
  "status": "approved",
  "data": {
    "id": 1,
    "vehicle_type": "Car",
    "vehicle_model": "Toyota Corolla",
    "vehicle_color": "White",
    "plate_number": "ABC123",
    "approved_at": "2026-04-28",
    "valid_from": "2026-02-01",
    "valid_until": "2026-06-01"
  }
}
```

#### Response — Status `rejected`

```json
{
  "success": true,
  "status": "rejected",
  "data": {
    "id": 1,
    "vehicle_type": "Car",
    "vehicle_model": "Toyota Corolla",
    "vehicle_color": "White",
    "plate_number": "ABC123",
    "rejection_reason": "Incomplete documentation",
    "rejected_at": "2026-04-28"
  }
}
```

---

### 5.5 POST `/api/v1/student/vehicle-requests`

**Purpose**: Submit a new vehicle access request.

| Property | Value |
|----------|-------|
| Auth Required | Yes — Bearer token |
| Method | `POST` |
| URL | `/api/v1/student/vehicle-requests` |

#### Request Headers

| Header | Value |
|--------|-------|
| Authorization | `Bearer {token}` |
| Content-Type | `application/json` |
| Accept | `application/json` |

#### Request Body

```json
{
  "vehicle_type": "Car",
  "vehicle_model": "Toyota Corolla",
  "vehicle_color": "White",
  "plate_number": "ABC123"
}
```

| Field | Type | Required | Rules |
|-------|------|----------|-------|
| `vehicle_type` | string | Yes | e.g. Car, Motorcycle |
| `vehicle_model` | string | Yes | e.g. Toyota Corolla |
| `vehicle_color` | string | Yes | e.g. White |
| `plate_number` | string | Yes | Vehicle plate number |

#### Business Rules

| Rule | Effect |
|------|--------|
| Student has a **pending** request | ❌ Blocked — returns 422 |
| Student has an **active approved** permit (today between semester dates) | ❌ Blocked — returns 422 |
| Student's latest request was **rejected** | ✅ Allowed — can submit new request |
| Student's previous approved permit has **expired** | ✅ Allowed — can submit new request |
| Student has **no** previous requests | ✅ Allowed |

> **Note**: Only *currently active* approved permits (where today falls between `semester_start_date` and `semester_end_date`) block new submissions. Future-dated approved permits do not block.

#### Success Response — `200 OK`

```json
{
  "success": true,
  "message": "Vehicle request submitted successfully",
  "data": {
    "id": 1,
    "status": "pending"
  }
}
```

#### Error Response — `422 Unprocessable Entity` (Business Rule Violation)

```json
{
  "success": false,
  "message": "You already have a pending vehicle request or active permit."
}
```

#### Error Response — `422 Unprocessable Entity` (Validation)

```json
{
  "message": "The vehicle type field is required.",
  "errors": {
    "vehicle_type": ["The vehicle type field is required."]
  }
}
```

---

### 5.6 GET `/api/v1/student/vehicle-requests/history`

**Purpose**: Retrieve all vehicle requests for the authenticated student, sorted newest first.

| Property | Value |
|----------|-------|
| Auth Required | Yes — Bearer token |
| Method | `GET` |
| URL | `/api/v1/student/vehicle-requests/history` |

#### Request Headers

| Header | Value |
|--------|-------|
| Authorization | `Bearer {token}` |
| Accept | `application/json` |

#### Success Response — `200 OK`

```json
{
  "success": true,
  "data": [
    {
      "id": 2,
      "vehicle_type": "Car",
      "vehicle_model": "Honda Civic",
      "vehicle_color": "Black",
      "plate_number": "XYZ789",
      "status": "pending",
      "valid_from": null,
      "valid_until": null,
      "rejection_reason": null,
      "created_at": "2026-04-28"
    },
    {
      "id": 1,
      "vehicle_type": "Car",
      "vehicle_model": "Toyota Corolla",
      "vehicle_color": "White",
      "plate_number": "ABC123",
      "status": "approved",
      "valid_from": "2026-02-01",
      "valid_until": "2026-06-01",
      "rejection_reason": null,
      "created_at": "2026-04-27"
    }
  ]
}
```

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Request ID |
| `vehicle_type` | string | Type of vehicle |
| `vehicle_model` | string | Vehicle model |
| `vehicle_color` | string | Vehicle color |
| `plate_number` | string | Plate number |
| `status` | string | `pending`, `approved`, or `rejected` |
| `valid_from` | string\|null | Semester start date (approved only) |
| `valid_until` | string\|null | Semester end date (approved only) |
| `rejection_reason` | string\|null | Reason for rejection (rejected only) |
| `created_at` | string | Date the request was submitted |

---

### 5.7 POST `/api/v1/gate/vehicle-access/check`

**Purpose**: Receives OCR plate text from the gate/LPR system to verify vehicle access.

| Property | Value |
|----------|-------|
| Auth Required | Yes — `X-GATE-API-KEY` header |
| Method | `POST` |
| URL | `/api/v1/gate/vehicle-access/check` |

#### Request Headers

| Header | Value |
|--------|-------|
| X-GATE-API-KEY | `your_gate_api_key` |
| Content-Type | `application/json` |
| Accept | `application/json` |

#### Request Body

```json
{
  "OCR": "س م ١ ٤ ٦ ٩"
}
```

| Field | Type | Required | Rules |
|-------|------|----------|-------|
| `OCR` | string | Yes | Max 100 chars |

#### Business Rules
- Only approved vehicle requests can allow entry.
- Permit must be valid today.
- Pending requests do not allow entry.
- Rejected requests do not allow entry.
- Expired approved permits do not allow entry.
- OCR text is normalized before comparison.
- This API is for gate devices only, not students/admins/frontend users.

#### Success Response — Allowed (`200 OK`)

```json
{
  "success": true,
  "access": "allowed",
  "message": "Vehicle permit is approved and valid.",
  "data": {
    "plate_number": "س م ١ ٤ ٦ ٩",
    "normalized_plate": "سم1469",
    "student": {
      "student_id": "YOUR_STUDENT_ID",
      "full_name": "Student Name",
      "faculty": "Faculty Name"
    },
    "permit": {
      "id": 1,
      "valid_from": "2026-02-01",
      "valid_until": "2026-06-30"
    }
  }
}
```

#### Success Response — Denied (`200 OK`)

```json
{
  "success": true,
  "access": "denied",
  "message": "No approved valid vehicle permit found for this plate.",
  "data": {
    "plate_number": "س م ١ ٤ ٦ ٩",
    "normalized_plate": "سم1469"
  }
}
```

#### Error Response — Unauthorized (`401 Unauthorized`)

```json
{
  "success": false,
  "message": "Unauthorized gate device."
}
```

---

## 6. Error Codes Summary

| HTTP Code | Meaning | When |
|-----------|---------|------|
| 200 | Success | Request processed successfully |
| 401 | Unauthenticated | Missing or invalid token |
| 403 | Forbidden | Token does not belong to a Student |
| 422 | Unprocessable Entity | Validation error or business rule violation |

---

## 7. Frontend Integration Notes

### Login Flow
1. Show a login form with **Student ID** and **Password** fields.
2. Send `POST /api/v1/student/login` with `student_id` (not email) and `password`.
3. On success, store the returned `data.token` in localStorage or a state manager.
4. Use the token for all subsequent API calls.

### Dashboard Page
- Call `GET /api/v1/student/profile` to load the student's information.
- Display: full name, student ID, email, faculty name, GPA, credits completed, credits required.

### Vehicle Page
1. Call `GET /api/v1/student/vehicle` to check the current vehicle state.
2. Based on the returned `status`:

| Status | UI Action |
|--------|-----------|
| `none` | Show "Apply for Vehicle Access" form |
| `pending` | Show "Your request is being reviewed" with vehicle details |
| `approved` | Show active permit card with validity dates |
| `rejected` | Show rejection reason + allow re-application |

3. The vehicle request form must collect and submit:
   - `vehicle_type`
   - `vehicle_model`
   - `vehicle_color`
   - `plate_number`

### History Page
- Call `GET /api/v1/student/vehicle-requests/history` to list all past requests.

### Logout
- Call `POST /api/v1/student/logout` and clear the stored token.

---

## 8. Testing Credentials

> [!WARNING]
> The following credentials are for **local/staging testing only**. No default student accounts exist in production. Students must be created manually from Filament by Super Admin or Academic Admin.

### Admin Dashboard (Filament)

| Field | Value |
|-------|-------|
| URL | `http://127.0.0.1:8000/admin` |
| Email | `admin@galala.edu.eg` |
| Password | *Set via ADMIN_PASSWORD in .env* |

> Admins log in through the Filament dashboard only. There is no admin API.

### Test Student (API)

| Field | Value |
|-------|-------|
| Student ID | *(Create manually in Filament)* |
| Password | *(Create manually in Filament)* |

---

## 9. Quick Start

```bash
# 1. Ensure MySQL is running (XAMPP) and database 'iaas_db' exists

# 2. Run migrations and seed
php artisan migrate:fresh --seed

# 3. Start the development server
php artisan serve

# 4. Test login
# Replace YOUR_STUDENT_ID and YOUR_PASSWORD with a student you created in Filament
curl -X POST http://127.0.0.1:8000/api/v1/student/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"student_id": "YOUR_STUDENT_ID", "password": "YOUR_PASSWORD"}'
```
