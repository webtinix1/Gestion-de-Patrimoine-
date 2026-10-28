<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\Users\SyncUserAccess;
use App\Filament\Resources\Users\UserActions;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UserActions::resetPassword(),
            UserActions::deactivate(),
            UserActions::reactivate(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var User $user */
        $user = $this->getRecord();

        return [
            ...$data,
            'role' => $user->getRoleNames()->first(),
            'sites' => $user->perimeterSiteIds() ?? [],
            'departments' => $user->perimeterDepartmentIds() ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        $attributes = Arr::except($data, ['role', 'sites', 'departments']);

        if ($record->is(auth()->user())) {
            unset($attributes['is_active']);
        }

        $record->update($attributes);

        app(SyncUserAccess::class)($record, $data['role'], $data['sites'] ?? [], $data['departments'] ?? []);

        return $record;
    }
}
