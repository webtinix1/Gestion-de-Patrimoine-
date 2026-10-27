<?php

namespace App\Policies;

use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Models\User;

/**
 * RG-84 : un compte se désactive, il ne se supprime jamais.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, PermissionAbility::View);
    }

    public function view(User $user, User $model): bool
    {
        return $this->allows($user, PermissionAbility::View);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, PermissionAbility::Create);
    }

    public function update(User $user, User $model): bool
    {
        return $this->allows($user, PermissionAbility::Update);
    }

    /**
     * On ne désactive pas son propre compte (l'application resterait sans administrateur).
     */
    public function deactivate(User $user, User $model): bool
    {
        return $this->allows($user, PermissionAbility::Update) && ! $user->is($model);
    }

    public function resetPassword(User $user, User $model): bool
    {
        return $this->allows($user, PermissionAbility::Update);
    }

    public function delete(User $user, User $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, User $model): bool
    {
        return false;
    }

    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }

    private function allows(User $user, PermissionAbility $ability): bool
    {
        return $user->is_active && $user->can(PermissionModule::Users->permission($ability));
    }
}
