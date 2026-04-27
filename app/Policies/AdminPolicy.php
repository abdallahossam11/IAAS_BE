<?php

namespace App\Policies;

use App\Models\Admin;

class AdminPolicy
{
    /**
     * Only super_admin can manage other admins.
     */
    public function viewAny(Admin $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(Admin $user, Admin $model): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(Admin $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(Admin $user, Admin $model): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Prevent deleting the root super admin (admin@galala.edu.eg)
     * and prevent admins from deleting their own account.
     */
    public function delete(Admin $user, Admin $model): bool
    {
        if ($model->email === 'admin@galala.edu.eg') {
            return false;
        }

        return $user->isSuperAdmin() && $user->id !== $model->id;
    }

    public function deleteAny(Admin $user): bool
    {
        return $user->isSuperAdmin();
    }
}
