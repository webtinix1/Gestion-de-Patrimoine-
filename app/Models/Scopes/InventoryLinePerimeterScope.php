<?php

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * RG-81 : une ligne d'inventaire est visible si son bien l'est (le scope du bien s'applique dans
 * whereHas) ou si l'affectation attendue au démarrage était dans le périmètre ; une ligne sans bien (non enregistré, RG-46) si le site et le département constatés
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

        $within = function (Builder $query, string $site, string $department) use ($model, $siteIds, $departmentIds): void {
            if ($siteIds !== null) {
                $query->whereIn($model->qualifyColumn($site), $siteIds);
            }

            if ($departmentIds !== null) {
                $query->whereIn($model->qualifyColumn($department), $departmentIds);
            }
        };

        $builder->where(function (Builder $query) use ($model, $within): void {
            // Affectation actuelle du bien, ou affectation attendue au démarrage (comme les mouvements).
            $query->whereHas('asset')
                ->orWhere(fn (Builder $query) => $query->whereNotNull($model->qualifyColumn('asset_id'))
                    ->where(fn (Builder $query) => $within($query, 'expected_site_id', 'expected_department_id')))
                ->orWhere(fn (Builder $query) => $query->whereNull($model->qualifyColumn('asset_id'))
                    ->where(fn (Builder $query) => $within($query, 'found_site_id', 'found_department_id')));
        });
    }
}
