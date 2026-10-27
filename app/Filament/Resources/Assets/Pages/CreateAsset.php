<?php

namespace App\Filament\Resources\Assets\Pages;

use App\Actions\Assets\CreateAsset as CreateAssetAction;
use App\Filament\Resources\Assets\AssetResource;
use App\Models\Asset;
use Filament\Resources\Pages\CreateRecord;

class CreateAsset extends CreateRecord
{
    protected static string $resource = AssetResource::class;

    protected static ?string $title = 'Enregistrer un bien';

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Asset
    {
        return app(CreateAssetAction::class)($data);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        /** @var Asset $asset */
        $asset = $this->getRecord();

        return 'Bien enregistré sous le numéro '.$asset->number;
    }

    protected function getRedirectUrl(): string
    {
        return AssetResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
