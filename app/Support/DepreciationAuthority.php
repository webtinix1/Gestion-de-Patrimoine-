<?php

namespace App\Support;

use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Models\User;

/**
 * RG-83 : seuls l'administrateur et le comptable modifient les règles d'amortissement, valident
 * les plans et clôturent les exercices. Règle appliquée en dur, en plus de la matrice des droits.
 */
final class DepreciationAuthority
{
    public static function isAccountingRole(?User $user): bool
    {
        return $user instanceof User
            && $user->is_active
            && $user->hasAnyRole([DefaultRoles::ADMIN, DefaultRoles::ACCOUNTANT]);
    }

    public static function allows(?User $user, PermissionAbility $ability): bool
    {
        return $user instanceof User
            && $user->is_active
            && $user->can(PermissionModule::Depreciation->permission($ability));
    }

    /**
     * Droit de la matrice ET rôle administrateur ou comptable.
     */
    public static function canManage(?User $user, PermissionAbility $ability): bool
    {
        return self::isAccountingRole($user) && self::allows($user, $ability);
    }
}
