<?php

namespace App\Filament\Resources\Maintenance\Widgets;

use App\Enums\MaintenanceStatus;
use App\Models\MaintenanceJob;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class MaintenanceStatsWidget extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $year = today()->year;

        return [
            Stat::make('En cours', MaintenanceJob::query()->where('status', MaintenanceStatus::Ongoing)->count())->color('info'),
            Stat::make('Planifiées', MaintenanceJob::query()->where('status', MaintenanceStatus::Planned)->count()),
            Stat::make('Terminées en '.$year, MaintenanceJob::query()->where('status', MaintenanceStatus::Done)->whereYear('ends_on', $year)->count()),
            Stat::make('Coût '.$year, Money::format((int) MaintenanceJob::query()->whereYear('starts_on', $year)->sum('cost')).' FCFA'),
        ];
    }
}
