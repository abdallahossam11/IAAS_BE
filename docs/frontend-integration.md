# Frontend Integration Guide

## Overview

This guide is for frontend developers integrating with the Galala University IAAS backend API. The frontend is a separate HTML/JS project that communicates with the Laravel backend via RESTful API calls.

---

## Base URL

```
http://127.0.0.1:8000/api/v1
```

All student endpoints are under `/api/v1/student/`.

---

## Required Headers

### All Requests

```
Accept: application/json
```

### Requests with Body (POST)

```
Content-Type: application/json
Accept: application/json
```

### Protected Requests (requires login)

```
Authorization: Bearer {token}
Accept: application/json
```

---

## Authentication Flow

### Login

1. Show a login form with **Student ID** and **Password** fields.
2. Send:

```http
POST /api/v1/student/login
Content-Type: application/json

{
  "student_id": "20230001",
  "password": "password123"
}
```

> **Important**: Login uses `student_id`, **not email**.

3. On success (200), extract the token from the response:

```javascript
const response = await fetch(`${BASE_URL}/student/login`, {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
  body: JSON.stringify({
    student_id: studentIdInput.value,
    password: passwordInput.value,
  }),
});

const data = await response.json();

if (data.success) {
  localStorage.setItem('student_token', data.data.token);
  localStorage.setItem('student_name', data.data.student.full_name);
  // Redirect to dashboard
} else {
  // Show error: data.message
}
```

4. On error (401), display: `"Invalid student ID or password"`.
5. On validation error (422), display field-level error messages from `errors`.

### Logout

```javascript
await fetch(`${BASE_URL}/student/logout`, {
  method: 'POST',
  headers: {
    'Authorization': `Bearer ${localStorage.getItem('student_token')}`,
    'Accept': 'application/json',
  },
});

localStorage.removeItem('student_token');
localStorage.removeItem('student_name');
// Redirect to login page
```

### Token Management

- Store the token in `localStorage` or a state manager after login.
- Include the token in the `Authorization` header for all protected requests.
- Clear the token on logout.
- If any protected request returns `401`, redirect the user to the login page (token expired/revoked).

---

## Dashboard / Profile Page

Call the profile endpoint after login to populate the student dashboard.

```http
GET /api/v1/student/profile
Authorization: Bearer {token}
```

### Response Fields

| Field | Type | Display As |
|-------|------|------------|
| `full_name` | string | Student Name |
| `student_id` | string | Student ID |
| `email` | string | Email Address |
| `faculty.name` | string | Faculty |
| `gpa` | float | GPA |
| `credits_completed` | integer | Credits Completed |
| `credits_required` | integer | Credits Required |

### Example JavaScript

```javascript
const response = await fetch(`${BASE_URL}/student/profile`, {
  headers: {
    'Authorization': `Bearer ${token}`,
    'Accept': 'application/json',
  },
});

const { data } = await response.json();

document.getElementById('student-name').textContent = data.full_name;
document.getElementById('student-id').textContent = data.student_id;
document.getElementById('faculty').textContent = data.faculty.name;
document.getElementById('gpa').textContent = data.gpa;
document.getElementById('credits').textContent =
  `${data.credits_completed} / ${data.credits_required}`;
```

---

## Vehicle Page

The vehicle page has two parts:
1. **Vehicle state display** — shows current status.
2. **Vehicle request form** — shown when the student can submit a new request.

### Step 1: Load Current State

```http
GET /api/v1/student/vehicle
Authorization: Bearer {token}
```

### Step 2: Render Based on Status

| Status | What to Show |
|--------|-------------|
| `none` | "Apply for Vehicle Access" button/form |
| `pending` | "Your request is being reviewed" with vehicle details and submitted date |
| `approved` | Active permit card with vehicle details, valid from/until dates |
| `rejected` | Rejection reason + "Submit New Request" button/form |

### Example JavaScript

```javascript
const response = await fetch(`${BASE_URL}/student/vehicle`, {
  headers: {
    'Authorization': `Bearer ${token}`,
    'Accept': 'application/json',
  },
});

const result = await response.json();

switch (result.status) {
  case 'none':
    showVehicleForm();
    break;
  case 'pending':
    showPendingCard(result.data);
    break;
  case 'approved':
    showApprovedPermit(result.data);
    break;
  case 'rejected':
    showRejectionNotice(result.data);
    showVehicleForm(); // Allow re-application
    break;
}
```

### Step 3: Submit Vehicle Request

When the user fills out the vehicle form:

```http
POST /api/v1/student/vehicle-requests
Authorization: Bearer {token}
Content-Type: application/json

{
  "vehicle_type": "Car",
  "vehicle_model": "Toyota Corolla",
  "vehicle_color": "White",
  "plate_number": "ABC123"
}
```

### Form Field Mapping

| Form Label | API Field |
|------------|-----------|
| Vehicle Type / Make | `vehicle_type` |
| Model | `vehicle_model` |
| Color | `vehicle_color` |
| Plate Number | `plate_number` |

### Handling Submit Response

```javascript
const response = await fetch(`${BASE_URL}/student/vehicle-requests`, {
  method: 'POST',
  headers: {
    'Authorization': `Bearer ${token}`,
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
  body: JSON.stringify({
    vehicle_type: form.vehicleType.value,
    vehicle_model: form.vehicleModel.value,
    vehicle_color: form.vehicleColor.value,
    plate_number: form.plateNumber.value,
  }),
});

const result = await response.json();

if (result.success) {
  // Show success message, reload vehicle state
  showPendingCard(result.data);
} else if (response.status === 422) {
  // Show error: result.message or result.errors
}
```

---

## Vehicle History Page

Display all past vehicle requests:

```http
GET /api/v1/student/vehicle-requests/history
Authorization: Bearer {token}
```

### Response Data (Array)

| Field | Display As |
|-------|------------|
| `vehicle_type` | Type |
| `vehicle_model` | Model |
| `vehicle_color` | Color |
| `plate_number` | Plate |
| `status` | Status (with color badge) |
| `valid_from` | Valid From (if approved) |
| `valid_until` | Valid Until (if approved) |
| `rejection_reason` | Reason (if rejected) |
| `created_at` | Submitted Date |

---

## Important Notes for Frontend Team

### Pages NOT Backed by APIs Yet

| Page | Status |
|------|--------|
| Public chatbot page | Do **not** call this Laravel backend. Chatbot APIs are handled by the AI team separately. Integration will be added later. |
| Student chatbot page | Same as above — handled by AI team. No backend endpoint exists. |
| Help / Support page | Not backed by ticket APIs yet. The `support_admin` role exists but the support ticket module is postponed. |

### Error Handling Summary

| HTTP Status | Meaning | Action |
|-------------|---------|--------|
| 200 | Success | Process response data |
| 401 | Unauthenticated | Redirect to login (token expired/missing) |
| 403 | Forbidden | Show access denied (token is not a student) |
| 422 | Validation error | Show field errors or business rule message |

### Testing Credentials

| Field | Value |
|-------|-------|
| Student ID | `20230001` |
| Password | `password123` |
