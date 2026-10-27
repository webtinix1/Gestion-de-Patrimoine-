<?php

namespace App\Filament\Resources\Assets\Pages;

use App\Actions\Assets\UpdateAsset;
use App\Exceptions\ConcurrentModificationException;
use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Support\DetectsConcurrentEdits;
use App\Models\Asset;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditAsset extends EditRecord
{
    use DetectsConcurrentEdits;

    protected static string $resource = AssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Asset $record */
        try {
            return app(UpdateAsset::class)($record, $data, $this->openedLockVersion);
        } catch (ConcurrentModificationException $exception) {
            $this->notifyConcurrentModification($exception);

            return $record;
        }
    }

    protected function getRedirectUrl(): string
    {
        return AssetResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
