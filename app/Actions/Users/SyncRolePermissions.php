<?php

namespace App\Actions\Users;

use App\Models\Role;
use App\Support\DefaultRoles;

/**
 * RG-80 : enregistre la matrice des droits d'un rôle, tracée avec avant et après (RG-70).
 * Le rôle Administrateur garde toujours tous les droits.
 */
class SyncRolePermissions
{
    /**
     * @param  list<string>  $permissions
     */
    public function __invoke(Role $role, array $permissions): void
    {
        if ($role->isLocked()) {
            $permissions = DefaultRoles::allPermissions();
        }

        $permissions = array_values(array_intersect(DefaultRoles::allPermissions(), $permissions));
        $before = $role->permissions()->pluck('name')->sort()->values()->all();

        $role->syncPermissions($permissions);

        $after = $role->permissions()->pluck('name')->sort()->values()->all();

        if ($before === $after) {
            return;
        }

        activity('roles')
            ->performedOn($role)
            ->event('permissions_updated')
            ->withProperties([
                'old' => ['retirés' => array_values(array_diff($before, $after))],
                'attributes' => ['ajoutés' => array_values(array_diff($after, $before))],
            ])
            ->log('Modification des droits du rôle « '.$role->name.' »');
    }
}
