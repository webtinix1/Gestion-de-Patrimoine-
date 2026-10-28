<?php

namespace App\Filament\Resources\Acquisitions\Pages;

use App\Actions\Acquisitions\AttachAcquisitionDocuments;
use App\Actions\Acquisitions\CreateAcquisition as CreateAcquisitionAction;
use App\Filament\Resources\Acquisitions\AcquisitionResource;
use App\Models\Acquisition;
use App\Models\DocumentType;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;

class CreateAcquisition extends CreateRecord
{
    protected static string $resource = AcquisitionResource::class;

    protected static ?string $title = 'Nouvelle acquisition';

    protected static bool $canCreateAnother = false;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Acquisition
    {
        $files = Arr::pull($data, 'attachments');
        $typeId = Arr::pull($data, 'attachment_type_id');

        $acquisition = app(CreateAcquisitionAction::class)($data);

        if (filled($files)) {
            app(AttachAcquisitionDocuments::class)($files, DocumentType::query()->active()->findOrFail($typeId), $acquisition);
        }

        return $acquisition;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        /** @var Acquisition $acquisition */
        $acquisition = $this->getRecord();

        return 'Brouillon '.$acquisition->reference.' enregistré';
    }

    protected function getRedirectUrl(): string
    {
        return AcquisitionResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
