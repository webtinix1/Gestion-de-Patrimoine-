<?php

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\DB;

/**
 * RG-81, RG-82 : un utilisateur au périmètre limité voit un inventaire si son périmètre le contient
 * (site et département couverts), ou s'il y est désigné (responsable ou participant). Les lignes
 * restent filtrées par le périmètre (InventoryLinePerimeterScope).
 *
 * @implements Scope<Model>
 */
class InventoryPerimeterScope implements Scope
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

        $builder->where(function (Builder $query) use ($model, $user, $siteIds, $departmentIds): void {
            $query->where(function (Builder $query) use ($model, $siteIds, $departmentIds): void {
                if ($siteIds !== null) {
                    $query->whereIn($model->qualifyColumn('perimeter_site_id'), $siteIds);
                }

                if ($departmentIds !== null) {
                    $query->whereIn($model->qualifyColumn('perimeter_department_id'), $departmentIds);
                }
            })
                ->orWhere($model->qualifyColumn('manager_id'), $user->getKey())
                ->orWhereIn($model->qualifyColumn('id'), DB::table('inventory_participants')->select('inventory_id')->where('user_id', $user->getKey()));
        });
    }
}
