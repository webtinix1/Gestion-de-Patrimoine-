<?php

namespace App\Filament\Resources\Maintenance\Pages;

use App\Enums\MaintenanceResult;
use App\Filament\Actions\MaintenanceJobActions;
use App\Filament\Resources\Maintenance\MaintenanceResource;
use App\Filament\Support\HoldsOperationVersions;
use App\Models\MaintenanceJob;
use App\Support\DisposalUrl;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ViewMaintenanceJob extends ViewRecord
{
    use HoldsOperationVersions;

    protected static string $resource = MaintenanceResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var MaintenanceJob $job */
        $job = $this->getRecord();

        return 'Intervention '.$job->reference;
    }

    protected function getHeaderActions(): array
    {
        return [
            MaintenanceJobActions::start(),
            MaintenanceJobActions::close(),
            EditAction::make(),
            // RG-61 : un résultat « irréparable » propose une sortie, si la page des sorties existe.
            Action::make('proposeDisposal')
                ->label('Proposer la sortie du patrimoine')
                ->icon(Heroicon::OutlinedArchiveBoxArrowDown)
                ->color('danger')
                ->visible(fn (MaintenanceJob $record): bool => $record->result === MaintenanceResult::Irreparable
                    && DisposalUrl::pageExists()
                    && $record->asset !== null
                    && ! $record->asset->isDisposed())
                ->url(fn (MaintenanceJob $record): string => DisposalUrl::for($record->asset_id)),
        ];
    }
}
