<?php

namespace App\Filament\Resources\Assets\Pages;

use App\Enums\AssetStatus;
use App\Filament\Actions\AssetReportActions;
use App\Filament\Actions\TransferAction;
use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\Loans\LoanResource;
use App\Filament\Resources\Maintenance\MaintenanceResource;
use App\Models\Asset;
use App\Models\Loan;
use App\Models\MaintenanceJob;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ViewAsset extends ViewRecord
{
    protected static string $resource = AssetResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var Asset $asset */
        $asset = $this->getRecord();

        return $asset->number.' · '.$asset->designation;
    }

    protected function getHeaderActions(): array
    {
        /** @var Asset $asset */
        $asset = $this->getRecord();

        return [
            TransferAction::single($asset),
            Action::make('loan')
                ->label('Prêter')
                ->icon(Heroicon::OutlinedArrowPathRoundedSquare)
                ->color('gray')
                ->visible(fn (): bool => in_array($asset->status, [AssetStatus::InService, AssetStatus::InStock], true)
                    && ! $asset->isDisposed()
                    && (auth()->user()?->can('create', Loan::class) ?? false))
                ->url(LoanResource::getUrl('create').'?'.http_build_query(['bien' => $asset->getKey()])),
            Action::make('maintenance')
                ->label('Maintenance')
                ->icon(Heroicon::OutlinedWrenchScrewdriver)
                ->color('gray')
                ->visible(fn (): bool => ! $asset->isDisposed() && (auth()->user()?->can('create', MaintenanceJob::class) ?? false))
                ->url(MaintenanceResource::getUrl('create').'?'.http_build_query(['bien' => $asset->getKey()])),
            AssetReportActions::create($asset),
            EditAction::make(),
        ];
    }
}
