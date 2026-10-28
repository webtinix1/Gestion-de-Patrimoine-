<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Support\DefaultRoles;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * RG-80 : permissions module × droit et rôles livrés. La matrice d'un rôle existant
 * n'est pas réécrite (l'administrateur a pu la modifier), sauf pour l'Administrateur.
 */
class RolesSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (DefaultRoles::allPermissions() as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (DefaultRoles::names() as $name) {
            $role = Role::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);

            if ($role->wasRecentlyCreated || $role->isLocked()) {
                $role->syncPermissions(DefaultRoles::permissionsFor($name));
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
