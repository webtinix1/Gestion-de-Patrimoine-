<?php

namespace App\Policies;

use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Models\AssetReport;
use App\Models\User;

/**
 * RG-63, RG-64 : le responsable consulte et crée ; le gestionnaire traite ou rejette (droit « valider »).
 * Un signalement ne se modifie pas et ne se supprime pas.
 */
class AssetReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, PermissionAbility::View);
    }

    public function view(User $user, AssetReport $report): bool
    {
        return $this->allows($user, PermissionAbility::View);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, PermissionAbility::Create);
    }

    public function handle(User $user, AssetReport $report): bool
    {
        return $this->allows($user, PermissionAbility::Validate) && $report->status->isOpen();
    }

    public function update(User $user, AssetReport $report): bool
    {
        return false;
    }

    public function delete(User $user, AssetReport $report): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, AssetReport $report): bool
    {
        return false;
    }

    public function forceDelete(User $user, AssetReport $report): bool
    {
        return false;
    }

    private function allows(User $user, PermissionAbility $ability): bool
    {
        return $user->is_active && $user->can(PermissionModule::AssetReports->permission($ability));
    }
}
