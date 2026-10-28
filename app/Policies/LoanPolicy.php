<?php

namespace App\Policies;

use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Models\Loan;
use App\Models\User;

/**
 * RG-55 à RG-58 : « créer » enregistre un prêt ou un emprunt, « modifier » enregistre un retour. Rien ne se supprime.
 */
class LoanPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, PermissionAbility::View);
    }

    public function view(User $user, Loan $loan): bool
    {
        return $this->allows($user, PermissionAbility::View);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, PermissionAbility::Create);
    }

    public function update(User $user, Loan $loan): bool
    {
        return $this->allows($user, PermissionAbility::Update) && $loan->isOngoing();
    }

    public function delete(User $user, Loan $loan): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Loan $loan): bool
    {
        return false;
    }

    public function forceDelete(User $user, Loan $loan): bool
    {
        return false;
    }

    private function allows(User $user, PermissionAbility $ability): bool
    {
        return $user->is_active && $user->can(PermissionModule::Loans->permission($ability));
    }
}
