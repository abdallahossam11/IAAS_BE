<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\Student;

class StudentPolicy
{
    /**
     * super_admin and academic_admin can manage students.
     */
    public function viewAny(Admin $user): bool
    {
        return $user->isSuperAdmin() || $user->isAcademicAdmin();
    }

    public function view(Admin $user, Student $model): bool
    {
        return $user->isSuperAdmin() || $user->isAcademicAdmin();
    }

    public function create(Admin $user): bool
    {
        return $user->isSuperAdmin() || $user->isAcademicAdmin();
    }

    public function update(Admin $user, Student $model): bool
    {
        return $user->isSuperAdmin() || $user->isAcademicAdmin();
    }

    /**
     * Allowed roles may delete a student ONLY when the student has no chatbot
     * history. The role model is unchanged; the chat-history condition is the
     * added guard (mirrors the DB restrictOnDelete backstop).
     */
    public function delete(Admin $user, Student $model): bool
    {
        if ($model->hasChatHistory()) {
            return false;
        }

        return $user->isSuperAdmin() || $user->isAcademicAdmin();
    }

    /**
     * Bulk-delete availability stays role-based; per-student chat-history
     * protection is enforced inside the bulk action (it skips protected
     * students), since deleteAny has no record context.
     */
    public function deleteAny(Admin $user): bool
    {
        return $user->isSuperAdmin() || $user->isAcademicAdmin();
    }
}
