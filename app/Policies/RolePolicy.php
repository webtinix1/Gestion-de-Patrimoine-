<?php

namespace App\Policies;

use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Models\Role;
use App\Models\User;
use App\Support\DefaultRoles;

/**
 * RG-80 : la matrice des droits est paramétrable par l'administrateur (module « Utilisateurs et rôles »).
 * Les cinq rôles livrés ne se suppriment pas ; le rôle Administrateur garde tous les droits.
 * Créer, modifier ou supprimer un rôle est réservé en dur au rôle Administrateur, comme les paramètres :
 * le droit « Utilisateurs et rôles » délégué à un autre rôle ne permet pas de s'accorder des droits.
 */
class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, PermissionAbility::View);
    }

    public function view(User $user, Role $role): bool
    {
        return $this->allows($user, PermissionAbility::View);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, PermissionAbility::Create) && $user->isAdministrator();
    }

    public function update(User $user, Role $role): bool
    {
        return $this->allows($user, PermissionAbility::Update) && $user->isAdministrator();
    }

    public function delete(User $user, Role $role): bool
    {
        return $this->allows($user, PermissionAbility::Delete)
            && $user->isAdministrator()
            && ! in_array($role->name, DefaultRoles::names(), true)
            && ! $role->users()->exists();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function allows(User $user, PermissionAbility $ability): bool
    {
        return $user->is_active && $user->can(PermissionModule::Users->permission($ability));
    }
}
