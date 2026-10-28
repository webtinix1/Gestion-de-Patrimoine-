<?php

namespace App\Policies;

use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Models\Document;
use App\Models\User;

/**
 * RG-66 : fichiers servis après contrôle des droits. Un document se voit s'il porte le droit
 * « documents.view » et si au moins un objet rattaché est dans le périmètre de l'utilisateur.
 */
class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, PermissionAbility::View);
    }

    public function view(User $user, Document $document): bool
    {
        return $this->allows($user, PermissionAbility::View)
            && Document::query()->visible()->whereKey($document->getKey())->exists();
    }

    public function create(User $user): bool
    {
        return $this->allows($user, PermissionAbility::Create);
    }

    public function update(User $user, Document $document): bool
    {
        return $this->allows($user, PermissionAbility::Update) && $this->view($user, $document);
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->allows($user, PermissionAbility::Delete) && ! $document->isAttached();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function allows(User $user, PermissionAbility $ability): bool
    {
        return $user->is_active && $user->can(PermissionModule::Documents->permission($ability));
    }
}
