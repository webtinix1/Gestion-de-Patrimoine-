<?php

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * RG-81 : une ligne d'inventaire est visible si son bien l'est (le scope du bien s'applique dans
 * whereHas) ; une ligne sans bien (non enregistré, RG-46) si le site et le département constatés
 * sont dans le périmètre.
 *
 * @implements Scope<Model>
 */
class InventoryLinePerimeterScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $siteIds = $user->perimeterSiteIds();
        $departmentIds = $user->perimeterDepartmentIds();

        if ($siteIds === null && $departmentIds === null) {
            return;
        }

        $builder->where(function (Builder $query) use ($model, $siteIds, $departmentIds): void {
            $query->whereHas('asset')
                ->orWhere(function (Builder $query) use ($model, $siteIds, $departmentIds): void {
                    $query->whereNull($model->qualifyColumn('asset_id'));

                    if ($siteIds !== null) {
                        $query->whereIn($model->qualifyColumn('found_site_id'), $siteIds);
                    }

                    if ($departmentIds !== null) {
                        $query->whereIn($model->qualifyColumn('found_department_id'), $departmentIds);
                    }
                });
        });
    }
}
