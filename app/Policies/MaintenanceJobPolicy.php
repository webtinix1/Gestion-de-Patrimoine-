<?php

namespace App\Policies;

use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Models\MaintenanceJob;
use App\Models\User;

/**
 * RG-60, RG-61 : une intervention terminée ne se modifie plus ; rien ne se supprime.
 */
class MaintenanceJobPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, PermissionAbility::View);
    }

    public function view(User $user, MaintenanceJob $job): bool
    {
        return $this->allows($user, PermissionAbility::View);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, PermissionAbility::Create);
    }

    public function update(User $user, MaintenanceJob $job): bool
    {
        return $this->allows($user, PermissionAbility::Update) && $job->isOpen();
    }

    public function delete(User $user, MaintenanceJob $job): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, MaintenanceJob $job): bool
    {
        return false;
    }

    public function forceDelete(User $user, MaintenanceJob $job): bool
    {
        return false;
    }

    private function allows(User $user, PermissionAbility $ability): bool
    {
        return $user->is_active && $user->can(PermissionModule::Maintenance->permission($ability));
    }
}
