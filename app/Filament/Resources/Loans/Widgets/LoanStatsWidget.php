<?php

namespace App\Filament\Resources\Loans\Widgets;

use App\Enums\LoanDirection;
use App\Enums\LoanStatus;
use App\Models\Loan;
use App\Models\LoanItem;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class LoanStatsWidget extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $ongoing = fn () => Loan::query()->where('direction', LoanDirection::Out)->where('status', LoanStatus::Ongoing);

        return [
            Stat::make('Prêts en cours', $ongoing()->count()),
            Stat::make('En retard', $ongoing()->whereDate('due_on', '<', today())->count())->color('danger'),
            Stat::make('Retour sous 7 jours', $ongoing()->whereDate('due_on', '>=', today())->whereDate('due_on', '<=', today()->addDays(7))->count())->color('warning'),
            Stat::make('Biens empruntés', LoanItem::query()
                ->whereHas('loan', fn ($query) => $query->where('direction', LoanDirection::In)->where('status', LoanStatus::Ongoing))
                ->count()),
        ];
    }
}
