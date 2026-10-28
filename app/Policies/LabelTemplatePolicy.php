<?php

namespace App\Policies;

use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Models\LabelTemplate;
use App\Models\User;

/**
 * Module « Étiquettes » : consulter pour voir, créer pour imprimer, modifier pour gérer les modèles.
 */
class LabelTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, PermissionAbility::View);
    }

    public function view(User $user, LabelTemplate $template): bool
    {
        return $this->allows($user, PermissionAbility::View);
    }

    public function print(User $user): bool
    {
        return $this->allows($user, PermissionAbility::Create);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, PermissionAbility::Update);
    }

    public function update(User $user, LabelTemplate $template): bool
    {
        return $this->allows($user, PermissionAbility::Update);
    }

    public function delete(User $user, LabelTemplate $template): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, LabelTemplate $template): bool
    {
        return false;
    }

    public function forceDelete(User $user, LabelTemplate $template): bool
    {
        return false;
    }

    private function allows(User $user, PermissionAbility $ability): bool
    {
        return $user->is_active && $user->can(PermissionModule::Labels->permission($ability));
    }
}
