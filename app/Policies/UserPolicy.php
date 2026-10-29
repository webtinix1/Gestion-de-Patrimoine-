<?php

namespace App\Policies;

use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Models\User;
use App\Support\DefaultRoles;

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
        return $this->allows($user, PermissionAbility::Update) && $this->mayManage($user, $model);
    }

    /**
     * On ne désactive pas son propre compte (l'application resterait sans administrateur).
     */
    public function deactivate(User $user, User $model): bool
    {
        return $this->allows($user, PermissionAbility::Update) && ! $user->is($model) && $this->mayManage($user, $model);
    }

    public function resetPassword(User $user, User $model): bool
    {
        return $this->allows($user, PermissionAbility::Update) && $this->mayManage($user, $model);
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

    /**
     * Un compte administrateur n'est modifié, réinitialisé ou désactivé que par un administrateur : sans
     * cela, déléguer la gestion des comptes reviendrait à donner le rôle Administrateur.
     */
    private function mayManage(User $user, User $model): bool
    {
        return ! $model->hasRole(DefaultRoles::ADMIN) || $user->isAdministrator();
    }

    private function allows(User $user, PermissionAbility $ability): bool
    {
        return $user->is_active && $user->can(PermissionModule::Users->permission($ability));
    }
}
