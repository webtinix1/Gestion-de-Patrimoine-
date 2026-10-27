<?php

namespace App\Policies;

use App\Enums\PermissionAbility;
use App\Enums\PermissionModule;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Règles communes aux référentiels : module « Structure » de la matrice des droits (RG-80).
 *
 * @template TModel of Model
 */
abstract class ReferentialPolicy
{
    protected PermissionModule $module = PermissionModule::Structure;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, PermissionAbility::View);
    }

    /**
     * @param  TModel  $model
     */
    public function view(User $user, Model $model): bool
    {
        return $this->allows($user, PermissionAbility::View);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, PermissionAbility::Create);
    }

    /**
     * @param  TModel  $model
     */
    public function update(User $user, Model $model): bool
    {
        return $this->allows($user, PermissionAbility::Update);
    }

    /**
     * Un élément utilisé se désactive, il ne se supprime pas.
     *
     * @param  TModel  $model
     */
    public function delete(User $user, Model $model): bool
    {
        return $this->allows($user, PermissionAbility::Delete)
            && method_exists($model, 'isInUse')
            && ! $model->isInUse();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    /**
     * @param  TModel  $model
     */
    public function restore(User $user, Model $model): bool
    {
        return false;
    }

    /**
     * @param  TModel  $model
     */
    public function forceDelete(User $user, Model $model): bool
    {
        return false;
    }

    /**
     * Réordonner une liste (états) revient à la modifier.
     */
    public function reorder(User $user): bool
    {
        return $this->allows($user, PermissionAbility::Update);
    }

    protected function allows(User $user, PermissionAbility $ability): bool
    {
        return $user->is_active && $user->can($this->module->permission($ability));
    }
}
