<?php

namespace App\Filament\Resources\AssetReports\Pages;

use App\Enums\ReportStatus;
use App\Filament\Actions\AssetReportActions;
use App\Filament\Resources\AssetReports\AssetReportResource;
use App\Models\AssetReport;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAssetReports extends ListRecords
{
    protected static string $resource = AssetReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AssetReportActions::create(),
        ];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $count = fn (array $statuses): int => AssetReport::query()->whereIn('status', $statuses)->count();

        return [
            'open' => Tab::make('À traiter')
                ->badge($count([ReportStatus::New, ReportStatus::InProgress]))
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [ReportStatus::New, ReportStatus::InProgress])),
            'new' => Tab::make('Nouveaux')
                ->badge($count([ReportStatus::New]))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ReportStatus::New)),
            'in_progress' => Tab::make('Pris en charge')
                ->badge($count([ReportStatus::InProgress]))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ReportStatus::InProgress)),
            'handled' => Tab::make('Traités')
                ->badge($count([ReportStatus::Handled]))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ReportStatus::Handled)),
            'rejected' => Tab::make('Rejetés')
                ->badge($count([ReportStatus::Rejected]))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ReportStatus::Rejected)),
            'all' => Tab::make('Tous'),
        ];
    }
}
