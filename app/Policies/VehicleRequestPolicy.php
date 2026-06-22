<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\VehicleRequest;

class VehicleRequestPolicy
{
    /**
     * super_admin and vehicle_admin can manage vehicle requests.
     */
    public function viewAny(Admin $user): bool
    {
        return $user->isSuperAdmin() || $user->isVehicleAdmin();
    }

    public function view(Admin $user, VehicleRequest $model): bool
    {
        return $user->isSuperAdmin() || $user->isVehicleAdmin();
    }

    /**
     * Vehicle requests are created by students via API, not by admins.
     */
    public function create(Admin $user): bool
    {
        return false;
    }

    public function update(Admin $user, VehicleRequest $model): bool
    {
        return $user->isSuperAdmin() || $user->isVehicleAdmin();
    }

    /**
     * A single vehicle request/permit may be deleted from Filament by
     * super_admin or vehicle_admin. Deleting an approved row also revokes the
     * student's active permit (the permit is the same vehicle_requests row).
     */
    public function delete(Admin $user, VehicleRequest $model): bool
    {
        return $user->isSuperAdmin() || $user->isVehicleAdmin();
    }

    /**
     * Vehicle requests should not be bulk deleted.
     */
    public function deleteAny(Admin $user): bool
    {
        return false;
    }
}
