<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\Users\GenerateTemporaryPassword;
use App\Actions\Users\SyncUserAccess;
use App\Filament\Resources\Users\UserActions;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    private ?string $temporaryPassword = null;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): User
    {
        $this->temporaryPassword = app(GenerateTemporaryPassword::class)();

        $user = User::query()->create([
            ...Arr::except($data, ['role', 'sites', 'departments']),
            'password' => $this->temporaryPassword,
            'must_change_password' => true,
            'is_active' => true,
        ]);

        app(SyncUserAccess::class)($user, $data['role'], $data['sites'] ?? [], $data['departments'] ?? []);

        return $user;
    }

    protected function getCreatedNotification(): null
    {
        return null;
    }

    protected function afterCreate(): void
    {
        /** @var User $user */
        $user = $this->getRecord();

        UserActions::notifyTemporaryPassword($user, (string) $this->temporaryPassword);
    }
}
