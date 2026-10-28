<?php

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * RG-81 : une ligne de prêt est visible si son bien l'est (le scope du bien s'applique dans whereHas).
 *
 * @implements Scope<Model>
 */
class LoanItemPerimeterScope implements Scope
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

        $builder->whereNotNull($model->qualifyColumn('asset_id'))->whereHas('asset');
    }
}
