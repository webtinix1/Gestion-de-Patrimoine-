<?php

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * RG-81 : un mouvement est visible si le bien concerné l'est (le scope du bien s'applique dans whereHas).
 *
 * @implements Scope<Model>
 */
class MovementPerimeterScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();

        if (! $user instanceof User || ($user->perimeterSiteIds() === null && $user->perimeterDepartmentIds() === null)) {
            return;
        }

        $builder->whereHas('asset');
    }
}
