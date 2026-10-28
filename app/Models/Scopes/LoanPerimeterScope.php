<?php

namespace App\Models\Scopes;

use App\Enums\LoanDirection;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * RG-81 : un prêt est visible si l'un de ses biens l'est. Un emprunt (bien d'un tiers, sans site ni
 * département) n'est visible que d'un utilisateur sans périmètre restreint (QL8-1).
 *
 * @implements Scope<Model>
 */
class LoanPerimeterScope implements Scope
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

        $builder->where($model->qualifyColumn('direction'), LoanDirection::Out->value)->whereHas('items');
    }
}
