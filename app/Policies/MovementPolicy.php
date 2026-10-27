<?php

namespace App\Policies;

use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Models\Movement;
use App\Models\User;

/**
 * RG-21 : un mouvement ne se modifie ni ne se supprime. « Créer » correspond à l'action Transférer.
 */
class MovementPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, PermissionAbility::View);
    }

    public function view(User $user, Movement $movement): bool
    {
        return $this->allows($user, PermissionAbility::View);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, PermissionAbility::Create);
    }

    public function update(User $user, Movement $movement): bool
    {
        return false;
    }

    public function delete(User $user, Movement $movement): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Movement $movement): bool
    {
        return false;
    }

    public function forceDelete(User $user, Movement $movement): bool
    {
        return false;
    }

    private function allows(User $user, PermissionAbility $ability): bool
    {
        return $user->is_active && $user->can(PermissionModule::Movements->permission($ability));
    }
}
