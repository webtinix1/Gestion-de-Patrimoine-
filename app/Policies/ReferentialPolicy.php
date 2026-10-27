<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Règles communes aux référentiels. Les droits fins par rôle arrivent au lot 2
 * (RG-80) : en attendant, tout compte actif gère les référentiels.
 *
 * @template TModel of Model
 */
abstract class ReferentialPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    /**
     * @param  TModel  $model
     */
    public function view(User $user, Model $model): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->is_active;
    }

    /**
     * @param  TModel  $model
     */
    public function update(User $user, Model $model): bool
    {
        return $user->is_active;
    }

    /**
     * Un élément utilisé se désactive, il ne se supprime pas.
     *
     * @param  TModel  $model
     */
    public function delete(User $user, Model $model): bool
    {
        return $user->is_active && method_exists($model, 'isInUse') && ! $model->isInUse();
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
}
