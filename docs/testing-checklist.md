# Testing Checklist

Manual testing procedures for the Galala University IAAS backend.

---

## A. Environment Setup

- [ ] MySQL is running (XAMPP or standalone)
- [ ] Database `iaas_db` exists in phpMyAdmin
- [ ] `composer install` completed successfully
- [ ] `.env` is configured with MySQL credentials
- [ ] `php artisan migrate:fresh --seed` runs without errors
  - Expected: 8 migrations pass, 3 seeders run
- [ ] `php artisan serve` starts the development server
  - Expected: Server running on `http://127.0.0.1:8000`
- [ ] `php artisan route:list --path=api` shows exactly 6 routes
  - Expected routes:
    - `POST api/v1/student/login`
    - `POST api/v1/student/logout`
    - `GET api/v1/student/profile`
    - `GET api/v1/student/vehicle`
    - `POST api/v1/student/vehicle-requests`
    - `GET api/v1/student/vehicle-requests/history`
- [ ] `GET http://127.0.0.1:8000/` returns JSON health check
  - Expected: `{"success":true,"message":"Galala University IAAS API is running"}`

---

## B. Filament Login

- [ ] Navigate to `http://127.0.0.1:8000/admin`
  - Expected: Filament login page appears
- [ ] Login with `admin@galala.edu.eg` / *(Your ADMIN_PASSWORD from .env)*
  - Expected: Dashboard loads with "Welcome" message
- [ ] Login with wrong password
  - Expected: Error message, login rejected
- [ ] Sidebar shows all 4 resources for super_admin
  - Expected: Admins, Faculties, Students, Vehicle Requests

---

## C. Role-Based Access

### Create Test Admins

- [ ] As super_admin, create admin: `academic@galala.edu.eg` / role: `academic_admin`
- [ ] As super_admin, create admin: `vehicle@galala.edu.eg` / role: `vehicle_admin`
- [ ] As super_admin, create admin: `support@galala.edu.eg` / role: `support_admin`

### super_admin Access

- [ ] Sees **Admins** in sidebar
- [ ] Sees **Faculties** in sidebar
- [ ] Sees **Students** in sidebar
- [ ] Sees **Vehicle Requests** in sidebar

### academic_admin Access

- [ ] Login as `academic@galala.edu.eg`
- [ ] Sees **Faculties** in sidebar
- [ ] Sees **Students** in sidebar
- [ ] Does NOT see **Admins** in sidebar
- [ ] Does NOT see **Vehicle Requests** in sidebar
- [ ] Navigate to `/admin/admins` directly → Expected: **403 Forbidden**
- [ ] Navigate to `/admin/vehicle-requests` directly → Expected: **403 Forbidden**

### vehicle_admin Access

- [ ] Login as `vehicle@galala.edu.eg`
- [ ] Sees **Vehicle Requests** in sidebar
- [ ] Does NOT see **Admins** in sidebar
- [ ] Does NOT see **Faculties** in sidebar
- [ ] Does NOT see **Students** in sidebar
- [ ] Navigate to `/admin/admins` directly → Expected: **403 Forbidden**
- [ ] Navigate to `/admin/students` directly → Expected: **403 Forbidden**

### support_admin Access

- [ ] Login as `support@galala.edu.eg`
- [ ] Sees only **Dashboard** (no resource links)
- [ ] Navigate to `/admin/admins` directly → Expected: **403 Forbidden**
- [ ] Navigate to `/admin/faculties` directly → Expected: **403 Forbidden**
- [ ] Navigate to `/admin/students` directly → Expected: **403 Forbidden**
- [ ] Navigate to `/admin/vehicle-requests` directly → Expected: **403 Forbidden**

---

## D. Protected Root Admin

- [ ] Go to Admins list as super_admin
- [ ] `admin@galala.edu.eg` row shows **Edit** but NO **Delete** button
- [ ] Other admin rows show both **Edit** and **Delete** buttons
- [ ] No bulk delete checkboxes appear in the admin list
- [ ] Edit `admin@galala.edu.eg`:
  - [ ] **Email** field is disabled/greyed out
  - [ ] **Role** field is disabled/greyed out (shows "Super Admin")
  - [ ] **Name** field is editable
  - [ ] **Password** field is editable
  - [ ] No **Delete** button in the page header

---

## E. Student API Tests

### Login

- [ ] `POST /api/v1/student/login` with `{"student_id":"YOUR_STUDENT_ID","password":"YOUR_PASSWORD"}`
  - Expected: `200` with `success: true` and `data.token`
- [ ] `POST /api/v1/student/login` with wrong password
  - Expected: `401` with `"Invalid student ID or password"`
- [ ] `POST /api/v1/student/login` with missing fields
  - Expected: `422` validation error

### Protected Route Without Token

- [ ] `GET /api/v1/student/profile` without Authorization header
  - Expected: `401` `"Unauthenticated."`

### Profile

- [ ] `GET /api/v1/student/profile` with valid token
  - Expected: `200` with `full_name`, `student_id`, `email`, `faculty`, `gpa`, `credits_completed`, `credits_required`
  - Verify: faculty is an object with `id` and `name`
  - Verify: no extra fields beyond the agreed list

### Logout

- [ ] `POST /api/v1/student/logout` with valid token
  - Expected: `200` with `"Logged out successfully"`
- [ ] Use the same token after logout
  - Expected: `401` (token revoked)

---

## F. Vehicle API Tests

### Initial State

- [ ] `GET /api/v1/student/vehicle` (no requests exist)
  - Expected: `status: "none"`, `data: null`

### Submit Request

- [ ] `POST /api/v1/student/vehicle-requests` with valid body
  ```json
  {"vehicle_type":"Car","vehicle_model":"Toyota Corolla","vehicle_color":"White","plate_number":"ABC123"}
  ```
  - Expected: `200` with `status: "pending"` and request ID

### Pending State

- [ ] `GET /api/v1/student/vehicle` after submitting
  - Expected: `status: "pending"` with vehicle details

### Duplicate Submission Block

- [ ] `POST /api/v1/student/vehicle-requests` again while pending
  - Expected: `422` with `"You already have a pending vehicle request or active permit."`

### Admin Approves Request

- [ ] Login to Filament as `vehicle@galala.edu.eg` or `admin@galala.edu.eg`
- [ ] Go to Vehicle Requests
- [ ] Click **Approve** on the pending request
- [ ] Enter semester start date (past date) and end date (future date)
- [ ] Confirm approval
  - Expected: Status badge changes to "approved"

### Approved State

- [ ] `GET /api/v1/student/vehicle` after approval
  - Expected: `status: "approved"` with `approved_at`, `valid_from`, `valid_until`

### Active Permit Blocks New Submission

- [ ] `POST /api/v1/student/vehicle-requests` while active permit exists
  - Expected: `422` with `"You already have a pending vehicle request or active permit."`

### Vehicle History

- [ ] `GET /api/v1/student/vehicle-requests/history`
  - Expected: Array of all requests, newest first
  - Verify: each entry has `id`, `vehicle_type`, `vehicle_model`, `vehicle_color`, `plate_number`, `status`, `valid_from`, `valid_until`, `rejection_reason`, `created_at`

### Rejection Flow

- [ ] (Requires a new pending request or database manipulation)
- [ ] Admin rejects a pending request with a reason
  - Expected: Status changes to "rejected"
- [ ] `GET /api/v1/student/vehicle` after rejection
  - Expected: `status: "rejected"` with `rejection_reason`
- [ ] `POST /api/v1/student/vehicle-requests` after rejection
  - Expected: `200` — new request allowed

### Expired Permit Flow

- [ ] (Requires a previously approved request with expired semester dates)
- [ ] `GET /api/v1/student/vehicle` with expired permit
  - Expected: `status: "none"`
- [ ] `POST /api/v1/student/vehicle-requests` with expired permit
  - Expected: `200` — new request allowed

---

## G. Frontend Integration Tests

- [ ] Login form sends `student_id` (not email) and `password`
- [ ] Successful login stores the returned token
- [ ] Dashboard page calls `GET /api/v1/student/profile` and displays data
- [ ] Vehicle page calls `GET /api/v1/student/vehicle` to determine current state
- [ ] Vehicle form submits `vehicle_type`, `vehicle_model`, `vehicle_color`, `plate_number`
- [ ] Logout calls `POST /api/v1/student/logout` and clears stored token
- [ ] After logout, protected pages redirect to login
