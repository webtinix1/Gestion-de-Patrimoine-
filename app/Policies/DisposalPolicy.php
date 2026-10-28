<?php

namespace App\Policies;

use App\Actions\Disposals\ValidateDisposal;
use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Models\Asset;
use App\Models\Disposal;
use App\Models\User;

/**
 * Sorties du patrimoine (E26, E27). RG-53 : une sortie ne se modifie, ne s'annule ni ne se supprime.
 * RG-83 : la validation est réservée à l'administrateur et au comptable.
 */
class DisposalPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, PermissionAbility::View);
    }

    public function view(User $user, Disposal $disposal): bool
    {
        return $this->allows($user, PermissionAbility::View)
            && Asset::query()->whereKey($disposal->asset_id)->exists();
    }

    public function create(User $user): bool
    {
        return $this->allows($user, PermissionAbility::Create);
    }

    /**
     * « Sortir » sur la fiche d'un bien : bien visible, pas encore sorti.
     */
    public function dispose(User $user, Asset $asset): bool
    {
        return $this->create($user) && ! $asset->isDisposed();
    }

    public function validate(User $user, Disposal $disposal): bool
    {
        return ! $disposal->isValidated()
            && ValidateDisposal::allows($user)
            && Asset::query()->whereKey($disposal->asset_id)->exists();
    }

    public function export(User $user): bool
    {
        return $this->allows($user, PermissionAbility::Export);
    }

    public function update(User $user, Disposal $disposal): bool
    {
        return false;
    }

    public function delete(User $user, Disposal $disposal): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Disposal $disposal): bool
    {
        return false;
    }

    public function forceDelete(User $user, Disposal $disposal): bool
    {
        return false;
    }

    private function allows(User $user, PermissionAbility $ability): bool
    {
        return $user->is_active && $user->can(PermissionModule::Disposals->permission($ability));
    }
}
