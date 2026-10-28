<?php

namespace App\Policies;

use App\Enums\PermissionAbility;
use App\Models\Asset;
use App\Models\DepreciationSchedule;
use App\Models\User;
use App\Support\DepreciationAuthority;

/**
 * Plans d'amortissement (E30, E31). Consultation selon la matrice et le périmètre (le bien doit être
 * visible) ; RG-83 : validation et modification des paramètres réservées à l'administrateur et au comptable.
 */
class DepreciationSchedulePolicy
{
    public function viewAny(User $user): bool
    {
        return DepreciationAuthority::allows($user, PermissionAbility::View);
    }

    public function view(User $user, DepreciationSchedule $schedule): bool
    {
        return DepreciationAuthority::allows($user, PermissionAbility::View)
            && Asset::query()->whereKey($schedule->asset_id)->exists();
    }

    public function export(User $user): bool
    {
        return DepreciationAuthority::allows($user, PermissionAbility::Export);
    }

    public function validate(User $user, DepreciationSchedule $schedule): bool
    {
        return $schedule->is_current
            && ! $schedule->isValidated()
            && DepreciationAuthority::canManage($user, PermissionAbility::Validate)
            && Asset::query()->whereKey($schedule->asset_id)->exists();
    }

    /**
     * RG-37 : « Modifier les paramètres » depuis le plan d'un bien.
     */
    public function changeParameters(User $user, Asset $asset): bool
    {
        return $asset->is_depreciable
            && ! $asset->isDisposed()
            && DepreciationAuthority::canManage($user, PermissionAbility::Update);
    }

    /**
     * Règles d'amortissement (E32) : paramètres généraux et règles par catégorie.
     */
    public function manageRules(User $user): bool
    {
        return DepreciationAuthority::canManage($user, PermissionAbility::Update);
    }

    public function update(User $user, DepreciationSchedule $schedule): bool
    {
        return false;
    }

    public function delete(User $user, DepreciationSchedule $schedule): bool
    {
        return false;
    }
}
