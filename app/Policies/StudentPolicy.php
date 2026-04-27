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

    public function delete(Admin $user, Student $model): bool
    {
        return $user->isSuperAdmin() || $user->isAcademicAdmin();
    }

    public function deleteAny(Admin $user): bool
    {
        return $user->isSuperAdmin() || $user->isAcademicAdmin();
    }
}
