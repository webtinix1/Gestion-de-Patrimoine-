<?php

namespace App\Policies;

use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Models\Acquisition;
use App\Models\User;

/**
 * RG-15 : une acquisition validée n'est plus modifiable ni supprimable. Seul un brouillon se supprime,
 * par qui peut le modifier. La validation (qui crée les fiches) exige le droit « valider » de la matrice.
 */
class AcquisitionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, PermissionAbility::View);
    }

    public function view(User $user, Acquisition $acquisition): bool
    {
        return $this->allows($user, PermissionAbility::View);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, PermissionAbility::Create);
    }

    public function update(User $user, Acquisition $acquisition): bool
    {
        return $this->allows($user, PermissionAbility::Update) && $acquisition->isDraft();
    }

    public function validate(User $user, Acquisition $acquisition): bool
    {
        return $this->allows($user, PermissionAbility::Validate) && $acquisition->isDraft();
    }

    public function delete(User $user, Acquisition $acquisition): bool
    {
        return $this->allows($user, PermissionAbility::Update) && $acquisition->isDraft();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Acquisition $acquisition): bool
    {
        return false;
    }

    public function forceDelete(User $user, Acquisition $acquisition): bool
    {
        return false;
    }

    private function allows(User $user, PermissionAbility $ability): bool
    {
        return $user->is_active && $user->can(PermissionModule::Acquisitions->permission($ability));
    }
}
