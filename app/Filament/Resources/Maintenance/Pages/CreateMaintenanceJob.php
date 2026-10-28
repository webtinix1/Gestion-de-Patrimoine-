<?php

namespace App\Filament\Resources\Maintenance\Pages;

use App\Actions\Maintenance\OpenMaintenanceJob;
use App\Exceptions\ConcurrentModificationException;
use App\Filament\Resources\Maintenance\MaintenanceResource;
use App\Filament\Support\OperationFields;
use App\Models\DocumentType;
use App\Models\MaintenanceJob;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class CreateMaintenanceJob extends CreateRecord
{
    protected static string $resource = MaintenanceResource::class;

    protected static ?string $title = 'Nouvelle intervention';

    protected static bool $canCreateAnother = false;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $files = Arr::pull($data, 'files');
        $typeId = Arr::pull($data, 'document_type_id');
        $version = Arr::pull($data, 'asset_version');

        try {
            return app(OpenMaintenanceJob::class)(
                $data,
                filled($version) ? (int) $version : null,
                $files ?? [],
                $typeId ? DocumentType::query()->active()->find($typeId) : null,
            );
        } catch (ValidationException|ConcurrentModificationException|AuthorizationException $exception) {
            OperationFields::notifyFailure('Enregistrement impossible', $exception);

            throw new Halt;
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        /** @var MaintenanceJob $job */
        $job = $this->getRecord();

        return 'Intervention '.$job->reference.' enregistrée';
    }

    protected function getRedirectUrl(): string
    {
        return MaintenanceResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
