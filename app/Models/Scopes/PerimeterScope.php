<?php

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * RG-81 : limite toute requête au périmètre (sites, départements) de l'utilisateur connecté.
 * Sans utilisateur connecté (console, file d'attente), aucun filtre.
 *
 * @implements Scope<Model>
 */
class PerimeterScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();

        if (! $user instanceof User || ! method_exists($model, 'perimeterColumns')) {
            return;
        }

        /** @var array{site?: string, department?: string, department_nullable?: bool} $columns */
        $columns = $model->perimeterColumns();

        $siteIds = $user->perimeterSiteIds();

        if ($siteIds !== null && isset($columns['site'])) {
            $builder->whereIn($model->qualifyColumn($columns['site']), $siteIds);
        }

        $departmentIds = $user->perimeterDepartmentIds();

        if ($departmentIds !== null && isset($columns['department'])) {
            $column = $model->qualifyColumn($columns['department']);

            if ($columns['department_nullable'] ?? false) {
                $builder->where(fn (Builder $query) => $query->whereIn($column, $departmentIds)->orWhereNull($column));
            } else {
                $builder->whereIn($column, $departmentIds);
            }
        }
    }
}
