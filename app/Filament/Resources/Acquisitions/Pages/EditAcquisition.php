<?php

namespace App\Filament\Resources\Acquisitions\Pages;

use App\Actions\Acquisitions\DeleteAcquisition;
use App\Actions\Acquisitions\UpdateAcquisition;
use App\Filament\Resources\Acquisitions\AcquisitionResource;
use App\Filament\Resources\Acquisitions\Actions\ValidateAcquisitionAction;
use App\Models\Acquisition;
use DomainException;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class EditAcquisition extends EditRecord
{
    protected static string $resource = AcquisitionResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var Acquisition $acquisition */
        $acquisition = $this->getRecord();

        return 'Modifier '.$acquisition->reference;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Acquisition $acquisition */
        $acquisition = $this->getRecord();

        $data['lines'] = $acquisition->lines->map(fn ($line): array => [
            'designation' => $line->designation,
            'asset_category_id' => $line->asset_category_id,
            'quantity' => $line->quantity,
            'unit_price' => $line->unit_price,
            'tracked_individually' => $line->tracked_individually,
        ])->all();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Acquisition $record */
        try {
            return app(UpdateAcquisition::class)($record, $data);
        } catch (DomainException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            throw new Halt;
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            ValidateAcquisitionAction::make(),
            DeleteAction::make()
                ->label('Supprimer le brouillon')
                ->using(function (Acquisition $record): bool {
                    try {
                        app(DeleteAcquisition::class)($record);
                    } catch (DomainException $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();

                        return false;
                    }

                    return true;
                }),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return AcquisitionResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
