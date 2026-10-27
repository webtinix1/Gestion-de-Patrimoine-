<?php

namespace App\Filament\Resources\Acquisitions\Pages;

use App\Enums\AcquisitionStatus;
use App\Filament\Resources\Acquisitions\AcquisitionResource;
use App\Models\Acquisition;
use App\Support\Money;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAcquisitions extends ListRecords
{
    protected static string $resource = AcquisitionResource::class;

    public function getSubheading(): string
    {
        return sprintf(
            '%d acquisitions · %d brouillons · %s FCFA au total. Une acquisition regroupe les biens d\'une même facture.',
            Acquisition::query()->count(),
            Acquisition::query()->where('status', AcquisitionStatus::Draft)->count(),
            Money::format((int) Acquisition::query()->sum('total_amount')),
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nouvelle acquisition'),
        ];
    }
}
