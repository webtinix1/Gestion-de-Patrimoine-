<?php

namespace App\Filament\Resources\Acquisitions\Pages;

use App\Filament\Resources\Acquisitions\AcquisitionResource;
use App\Filament\Resources\Acquisitions\Actions\ValidateAcquisitionAction;
use App\Models\Acquisition;
use App\Support\LabelsUrl;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ViewAcquisition extends ViewRecord
{
    protected static string $resource = AcquisitionResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var Acquisition $acquisition */
        $acquisition = $this->getRecord();

        return 'Acquisition '.$acquisition->reference;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            ValidateAcquisitionAction::make(),
            Action::make('printLabels')
                ->label('Imprimer les étiquettes')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->visible(fn (Acquisition $record): bool => $record->isValidated() && $record->assets()->exists())
                ->url(fn (Acquisition $record): string => LabelsUrl::for($record->assets()->orderBy('assets.number')->pluck('assets.id')->map(fn (mixed $id): int => (int) $id))),
        ];
    }
}
