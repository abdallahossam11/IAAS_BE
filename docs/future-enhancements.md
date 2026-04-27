# Future Enhancements

This document lists features and modules that are **planned but not yet implemented**. They are documented here to guide future development phases.

> **⚠️ None of the items below exist in the current codebase.** Do not reference them as available features.

---

## 1. OTP / Email Verification

**Purpose**: Add email-based OTP verification for student login to improve authentication security.

**Why Postponed**: The core authentication system needed to be established first with basic student_id + password login. OTP adds complexity that is better layered on after the foundation is stable.

**Expected Impact**:
- New SMTP configuration in `.env`.
- New fields or table for OTP codes and expiry.
- Modified login flow: login → send OTP → verify OTP → issue token.
- Frontend changes: additional OTP input step after password.

---

## 2. Bulk Student Upload

**Purpose**: Allow academic admins to upload student records in bulk from Excel/CSV files instead of creating them one by one.

**Why Postponed**: Individual student creation through Filament is sufficient for initial deployment. Bulk upload requires file parsing, validation, error reporting, and duplicate handling which adds significant complexity.

**Expected Impact**:
- New Filament action or page for file upload.
- Excel/CSV parsing library (e.g., `maatwebsite/excel`).
- Validation rules for each row.
- Error report for failed rows.
- No new API endpoints (admin-only feature).

---

## 3. Support Ticket System

**Purpose**: Allow students to submit support tickets and communicate with support staff through the platform.

**Why Postponed**: Vehicle access and student profile features were prioritized. The `support_admin` role already exists in preparation for this module.

**Expected Impact**:
- New `support_tickets` table (or similar).
- New `SupportTicket` model.
- New student API endpoints for creating/viewing tickets.
- New Filament resource for `support_admin` to manage tickets.
- Possible real-time notifications.

---

## 4. Public Chatbot API Integration

**Purpose**: Provide a public-facing chatbot on the university website for general inquiries (admissions, programs, campus info).

**Why Postponed**: The chatbot is being developed separately by the AI team. This backend will integrate with it once the AI service API is ready.

**Expected Impact**:
- New API endpoint(s) to proxy or relay chatbot requests.
- Integration with external AI/LLM service.
- No authentication required (public access).
- May require rate limiting.

---

## 5. Student Chatbot API Integration

**Purpose**: Provide a personalized chatbot for logged-in students that can answer questions about their academic progress, schedule, and university services.

**Why Postponed**: Same as the public chatbot — the AI team is developing the service separately. This backend will provide authenticated student context to the AI service.

**Expected Impact**:
- New authenticated API endpoint(s).
- Student context passed to AI service (profile, GPA, credits, etc.).
- Chat history storage (possibly new table).
- Sanctum authentication required.

---

## 6. Additional Student Fields

**Purpose**: Extend the student profile with additional data if required by future features.

**Why Postponed**: The current scope explicitly limits student fields to: `student_id`, `full_name`, `email`, `password`, `faculty_id`, `gpa`, `credits_completed`, `credits_required`. Additional fields should only be added when there is a concrete requirement.

**Expected Impact**:
- New migration to add columns to `students` table.
- Updated model fillable/casts.
- Updated Filament form and table.
- Updated API profile response.
- Possible examples: phone number, national ID, profile photo, address.

---

## 7. Vehicle Permit QR Code / Card

**Purpose**: Generate a downloadable or printable vehicle permit card with a QR code for campus gate scanning.

**Why Postponed**: The approval workflow needed to be established first. QR/card generation is a presentation layer feature that can be added on top of the existing data.

**Expected Impact**:
- QR code generation library.
- New API endpoint to download permit as PDF/image.
- QR code encodes permit ID or verification URL.
- Campus gate scanning integration.

---

## 8. Audit Logs

**Purpose**: Track all admin actions (create, update, delete, approve, reject) for accountability and compliance.

**Why Postponed**: Not a functional requirement for the initial release. Can be added later as a cross-cutting concern.

**Expected Impact**:
- New `audit_logs` table or use a package (e.g., `spatie/laravel-activitylog`).
- Automatic logging of model events.
- New Filament page for super_admin to view logs.
- No API impact (admin-only feature).

---

## 9. Custom Permission System

**Purpose**: Replace the fixed 4-role system with a flexible permission-based system where each admin can have granular permissions.

**Why Postponed**: The current 4-role system is sufficient for the university's needs. A full permission system adds complexity and administrative overhead that is not justified at this stage.

**Expected Impact**:
- New `permissions` and `admin_permission` tables (or use `spatie/laravel-permission`).
- Updated policies to check individual permissions instead of roles.
- New Filament UI for managing permissions per admin.
- Migration path from fixed roles to permissions.
