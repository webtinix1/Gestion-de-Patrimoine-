<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Actions\Users\SyncRolePermissions;
use App\Enums\PermissionModule;
use App\Filament\Resources\Roles\RoleResource;
use App\Models\Role;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Role $role */
        $role = $this->getRecord();
        $granted = $role->permissions()->pluck('name');

        foreach (PermissionModule::cases() as $module) {
            $data[RoleResource::field($module)] = $granted
                ->filter(fn (string $name): bool => str_starts_with($name, $module->value.'.'))
                ->values()
                ->all();
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Role $record */
        if (! $record->isDefault() && isset($data['name'])) {
            $record->update(['name' => $data['name']]);
        }

        app(SyncRolePermissions::class)($record, RoleResource::permissionsFromData($data));

        return $record;
    }
}
