<?php

namespace App\Filament\Resources\Loans\Pages;

use App\Enums\LoanDirection;
use App\Enums\LoanStatus;
use App\Filament\Resources\Loans\LoanResource;
use App\Filament\Resources\Loans\Widgets\LoanStatsWidget;
use App\Filament\Support\HoldsOperationVersions;
use App\Models\Loan;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListLoans extends ListRecords
{
    use HoldsOperationVersions;

    protected static string $resource = LoanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nouveau prêt ou emprunt'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [LoanStatsWidget::class];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $ongoingOut = fn (): Builder => Loan::query()->where('direction', LoanDirection::Out)->where('status', LoanStatus::Ongoing);

        return [
            'ongoing' => Tab::make('Prêts en cours')
                ->badge($ongoingOut()->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('direction', LoanDirection::Out)->where('status', LoanStatus::Ongoing)),
            'late' => Tab::make('En retard')
                ->badge($ongoingOut()->whereDate('due_on', '<', today())->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('direction', LoanDirection::Out)->where('status', LoanStatus::Ongoing)->whereDate('due_on', '<', today())),
            'borrowed' => Tab::make('Biens empruntés')
                ->badge(Loan::query()->where('direction', LoanDirection::In)->where('status', LoanStatus::Ongoing)->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('direction', LoanDirection::In)->where('status', LoanStatus::Ongoing)),
            'history' => Tab::make('Historique')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', LoanStatus::Returned)),
        ];
    }
}
