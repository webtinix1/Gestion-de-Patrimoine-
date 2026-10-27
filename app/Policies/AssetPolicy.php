<?php

namespace App\Policies;

use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Models\Asset;
use App\Models\User;

/**
 * RG-50 : un bien ne se supprime jamais. RG-52 / E43 : un bien sorti est en lecture seule.
 * RG-82 : le responsable de département consulte, il ne modifie aucune fiche (matrice).
 */
class AssetPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, PermissionAbility::View);
    }

    public function view(User $user, Asset $asset): bool
    {
        return $this->allows($user, PermissionAbility::View);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, PermissionAbility::Create);
    }

    public function update(User $user, Asset $asset): bool
    {
        return $this->allows($user, PermissionAbility::Update) && ! $asset->isDisposed();
    }

    public function export(User $user): bool
    {
        return $this->allows($user, PermissionAbility::Export);
    }

    public function delete(User $user, Asset $asset): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Asset $asset): bool
    {
        return false;
    }

    public function forceDelete(User $user, Asset $asset): bool
    {
        return false;
    }

    private function allows(User $user, PermissionAbility $ability): bool
    {
        return $user->is_active && $user->can(PermissionModule::Assets->permission($ability));
    }
}
