<?php

namespace App\Filament\Resources\Disposals\Pages;

use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\Disposals\Actions\ValidateDisposalAction;
use App\Filament\Resources\Disposals\DisposalResource;
use App\Models\DepreciationSchedule;
use App\Models\Disposal;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ViewDisposal extends ViewRecord
{
    protected static string $resource = DisposalResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var Disposal $disposal */
        $disposal = $this->getRecord();

        return 'Sortie '.$disposal->reference.' · '.$disposal->asset->number;
    }

    protected function getHeaderActions(): array
    {
        return [
            ValidateDisposalAction::make(),
            Action::make('asset')
                ->label('Fiche du bien')
                ->icon(Heroicon::OutlinedCube)
                ->color('gray')
                ->url(fn (Disposal $record): string => AssetResource::getUrl('view', ['record' => $record->asset_id])),
            Action::make('schedule')
                ->label('Plan d\'amortissement arrêté')
                ->icon(Heroicon::OutlinedChartBar)
                ->color('gray')
                ->visible(fn (Disposal $record): bool => (auth()->user()?->can('viewAny', DepreciationSchedule::class) ?? false)
                    && DepreciationSchedule::query()->where('asset_id', $record->asset_id)->exists())
                ->url(fn (Disposal $record): string => AssetResource::getUrl('depreciation', ['record' => $record->asset_id])),
        ];
    }
}
