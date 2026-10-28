<?php

namespace App\Policies;

use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Models\Setting;
use App\Models\User;
use App\Support\DefaultRoles;

/**
 * E41, RG-80 : l'écran Paramètres est réservé à l'administrateur. Règle appliquée en dur (rôle
 * Administrateur) en plus du droit « Paramètres » de la matrice : un rôle personnalisé auquel on
 * accorderait ce droit n'y accède pas.
 */
class SettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdministrator($user) && $user->can(PermissionModule::Settings->permission(PermissionAbility::View));
    }

    public function update(User $user): bool
    {
        return $this->isAdministrator($user) && $user->can(PermissionModule::Settings->permission(PermissionAbility::Update));
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, Setting $setting): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function isAdministrator(User $user): bool
    {
        return $user->is_active && $user->hasRole(DefaultRoles::ADMIN);
    }
}
