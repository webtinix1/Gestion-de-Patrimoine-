<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Actions\Users\SyncRolePermissions;
use App\Filament\Resources\Roles\RoleResource;
use App\Models\Role;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Role
    {
        $role = Role::query()->create(['name' => $data['name'], 'guard_name' => 'web']);

        activity('roles')->performedOn($role)->event('created')->log('Création du rôle « '.$role->name.' »');

        app(SyncRolePermissions::class)($role, RoleResource::permissionsFromData($data));

        return $role;
    }
}
