<?php

namespace App\Filament\Resources\Maintenance\Pages;

use App\Filament\Resources\Maintenance\MaintenanceResource;
use App\Filament\Resources\Maintenance\Widgets\MaintenanceStatsWidget;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMaintenanceJobs extends ListRecords
{
    protected static string $resource = MaintenanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nouvelle intervention'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [MaintenanceStatsWidget::class];
    }
}
