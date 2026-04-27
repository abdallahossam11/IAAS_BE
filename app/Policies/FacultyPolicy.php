<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\Faculty;

class FacultyPolicy
{
    /**
     * super_admin and academic_admin can manage faculties.
     */
    public function viewAny(Admin $user): bool
    {
        return $user->isSuperAdmin() || $user->isAcademicAdmin();
    }

    public function view(Admin $user, Faculty $model): bool
    {
        return $user->isSuperAdmin() || $user->isAcademicAdmin();
    }

    public function create(Admin $user): bool
    {
        return $user->isSuperAdmin() || $user->isAcademicAdmin();
    }

    public function update(Admin $user, Faculty $model): bool
    {
        return $user->isSuperAdmin() || $user->isAcademicAdmin();
    }

    public function delete(Admin $user, Faculty $model): bool
    {
        return $user->isSuperAdmin() || $user->isAcademicAdmin();
    }

    public function deleteAny(Admin $user): bool
    {
        return $user->isSuperAdmin() || $user->isAcademicAdmin();
    }
}
