# Filament Admin Dashboard Guide

## Accessing the Dashboard

| Field | Value |
|-------|-------|
| URL | `http://127.0.0.1:8000/admin` |
| Login | Email + Password |

### Seeded Root Admin

| Field | Value |
|-------|-------|
| Email | `admin@galala.edu.eg` |
| Password | `password123` |
| Role | `super_admin` |

> **⚠️ This account is protected.** It cannot be deleted, its role cannot be changed from `super_admin`, and its email cannot be changed. These protections are enforced at both the UI and backend levels.

---

## Admin Roles

The system has 4 admin roles. Each role controls which Filament resources are accessible — both in sidebar navigation and via direct URL access.

### Role Access Matrix

| Resource | super_admin | academic_admin | vehicle_admin | support_admin |
|----------|:-----------:|:--------------:|:-------------:|:-------------:|
| Admins | ✅ | ❌ | ❌ | ❌ |
| Faculties | ✅ | ✅ | ❌ | ❌ |
| Students | ✅ | ✅ | ❌ | ❌ |
| Vehicle Requests | ✅ | ❌ | ✅ | ❌ |

### Role Descriptions

#### super_admin
- Full access to all resources.
- Can create, edit, and delete other admin accounts.
- Can manage faculties, students, and vehicle requests.
- The only role that can access the Admins resource.

#### academic_admin
- Manages **Faculties** and **Students**.
- Can create, edit, and delete faculty records.
- Can create, edit, and delete student records.
- Cannot access Admins or Vehicle Requests.

#### vehicle_admin
- Manages **Vehicle Requests**.
- Can view vehicle requests submitted by students.
- Can **approve** pending requests (sets semester dates).
- Can **reject** pending requests (provides rejection reason).
- Cannot access Admins, Faculties, or Students.

#### support_admin
- Can log in to the Filament dashboard.
- No active resources are assigned yet.
- Reserved for a future support ticket module.

---

## Resources

### 1. Admins Resource

**Access**: `super_admin` only

**Location**: Sidebar → User Management → Admins

**Features**:
- View a list of all admin accounts with name, email, and role badge.
- Create new admin accounts.
- Edit existing admin accounts.
- Delete admin accounts (except the protected root admin).

**Creating an Admin**:

| Field | Required | Notes |
|-------|----------|-------|
| Name | Yes | Admin's full name |
| Email | Yes | Must be unique |
| Password | Yes (create) | Automatically hashed |
| Role | Yes | Select: Super Admin, Vehicle Admin, Academic Admin, or Support Admin |

**Editing an Admin**:
- Password is optional on edit. Leave blank to keep the current password.
- When editing `admin@galala.edu.eg`:
  - The **Email** field is disabled.
  - The **Role** field is disabled (locked to Super Admin).
  - Name and password can still be changed.

**Delete Protection**:
- The `admin@galala.edu.eg` row does not show a Delete button.
- The Edit page for this account does not show a Delete header action.
- Bulk delete has been removed from this resource entirely.
- The policy also enforces that an admin cannot delete their own account.

---

### 2. Faculties Resource

**Access**: `super_admin` and `academic_admin`

**Location**: Sidebar → Academic → Faculties

**Features**:
- View all faculties with student count.
- Create new faculties.
- Edit faculty names.
- Delete faculties.

**Fields**:

| Field | Required | Notes |
|-------|----------|-------|
| Name | Yes | Must be unique |

**Seeded Faculties** (9):
1. Engineering
2. Computer Science
3. Business
4. Medicine
5. Dentistry
6. Pharmacy
7. Nursing
8. Art and Design
9. Administrative Sciences

---

### 3. Students Resource

**Access**: `super_admin` and `academic_admin`

**Location**: Sidebar → Academic → Students

**Features**:
- View all students with ID, name, email, faculty, and GPA.
- Filter students by faculty.
- Create new student accounts.
- Edit student records.
- Delete students.

> **Note**: Students cannot self-register. All student accounts are created by admins through this resource.

**Fields**:

| Field | Required | Validation | Notes |
|-------|----------|------------|-------|
| Student ID | Yes | Unique | Used for API login |
| Full Name | Yes | Max 255 chars | |
| Email | Yes | Unique, valid email | |
| Password | Yes (create) | Max 255 chars | Auto-hashed. Optional on edit. |
| Faculty | Yes | Must exist | Searchable dropdown |
| GPA | No | 0.00 – 4.00 | Defaults to 0 |
| Credits Completed | No | Integer ≥ 0 | Defaults to 0 |
| Credits Required | No | Integer ≥ 0 | Defaults to 0 |

---

### 4. Vehicle Requests Resource

**Access**: `super_admin` and `vehicle_admin`

**Location**: Sidebar → Vehicle Management → Vehicle Requests

**Features**:
- View all vehicle requests with student info, vehicle details, and status.
- Filter requests by status (Pending, Approved, Rejected).
- View detailed request information.
- **Approve** pending requests.
- **Reject** pending requests.

> **Note**: There is no "Create" page. Vehicle requests are submitted by students through the API only.

**Table Columns**:
- Student Name (searchable)
- Student ID (searchable)
- Vehicle Type, Model, Color
- Plate Number (searchable)
- Status (badge: yellow=pending, green=approved, red=rejected)
- Created At (sorted newest first)
- Additional toggleable columns: semester dates, approved_at, rejection_reason, reviewed by

#### Approving a Request

1. Find a pending request in the list.
2. Click the **Approve** (green checkmark) action button.
3. A confirmation modal appears with two required fields:
   - **Semester Start Date** — when the permit becomes active.
   - **Semester End Date** — when the permit expires (must be after start date).
4. Click **Confirm**.
5. The request status changes to `approved`, and the reviewing admin is recorded.

**What happens on approval**:
- `status` → `approved`
- `admin_id` → current admin's ID
- `approved_at` → current timestamp
- `semester_start_date` → entered date
- `semester_end_date` → entered date

**Business rule check**: If the student already has an active approved permit (today between semester dates), the approval is blocked with an error notification.

#### Rejecting a Request

1. Find a pending request in the list.
2. Click the **Reject** (red X) action button.
3. A confirmation modal appears with one required field:
   - **Rejection Reason** — explanation for the student (max 1000 chars).
4. Click **Confirm**.
5. The request status changes to `rejected`.

**What happens on rejection**:
- `status` → `rejected`
- `admin_id` → current admin's ID
- `rejection_reason` → entered text
- Dates and `approved_at` are cleared

#### View Details

Click the **View** (eye icon) action to see a detailed read-only page with:
- Student information (name, ID, email, faculty)
- Vehicle details (type, model, color, plate)
- Request status, reviewer, dates, and rejection reason
