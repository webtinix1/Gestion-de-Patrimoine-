<?php

namespace App\Policies;

use App\Enums\FiscalYearStatus;
use App\Enums\PermissionAbility;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\DepreciationAuthority;

/**
 * E33. RG-41, RG-83 : création, ouverture et clôture réservées à l'administrateur et au comptable.
 * RG-43 : un exercice ne se supprime pas et sa clôture ne s'annule pas.
 */
class FiscalYearPolicy
{
    public function viewAny(User $user): bool
    {
        return DepreciationAuthority::allows($user, PermissionAbility::View);
    }

    public function view(User $user, FiscalYear $fiscalYear): bool
    {
        return DepreciationAuthority::allows($user, PermissionAbility::View);
    }

    public function create(User $user): bool
    {
        return DepreciationAuthority::canManage($user, PermissionAbility::Create);
    }

    public function open(User $user, FiscalYear $fiscalYear): bool
    {
        return $fiscalYear->status === FiscalYearStatus::Prepared
            && DepreciationAuthority::canManage($user, PermissionAbility::Update);
    }

    public function close(User $user, FiscalYear $fiscalYear): bool
    {
        return $fiscalYear->status === FiscalYearStatus::Open
            && DepreciationAuthority::canManage($user, PermissionAbility::Validate);
    }

    public function update(User $user, FiscalYear $fiscalYear): bool
    {
        return false;
    }

    public function delete(User $user, FiscalYear $fiscalYear): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
