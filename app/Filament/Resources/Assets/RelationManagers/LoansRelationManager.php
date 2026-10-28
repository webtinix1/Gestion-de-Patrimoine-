<?php

namespace App\Filament\Resources\Assets\RelationManagers;

use App\Filament\Resources\Loans\LoanResource;
use App\Models\Loan;
use App\Models\LoanItem;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * E06 : onglet « Prêts » d'un bien, en lecture seule (RG-55 à RG-57).
 */
class LoansRelationManager extends RelationManager
{
    protected static string $relationship = 'loanItems';

    protected static ?string $title = 'Prêts';

    protected static ?string $modelLabel = 'prêt';

    protected static ?string $pluralModelLabel = 'prêts';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('viewAny', Loan::class) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['loan', 'conditionOut', 'conditionBack']))
            ->emptyStateHeading('Aucun prêt')
            ->emptyStateDescription('Ce bien n\'a jamais été prêté.')
            ->columns([
                TextColumn::make('loan.reference')
                    ->label('N°')
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->color('primary')
                    ->url(fn (LoanItem $record): string => LoanResource::getUrl('view', ['record' => $record->loan_id])),
                TextColumn::make('loan.borrower_name')->label('Bénéficiaire'),
                TextColumn::make('loan.out_on')->label('Sortie')->date('d/m/Y'),
                TextColumn::make('loan.due_on')->label('Retour prévu')->date('d/m/Y'),
                TextColumn::make('loan.returned_on')->label('Retour effectif')->date('d/m/Y')->placeholder('—'),
                TextColumn::make('conditionOut.label')->label('État départ'),
                TextColumn::make('conditionBack.label')
                    ->label('État retour')
                    ->placeholder('—')
                    ->badge()
                    ->color(fn (LoanItem $record): string => $record->isDegraded() ? 'warning' : 'success'),
                TextColumn::make('situation')
                    ->label('Situation')
                    ->state(fn (LoanItem $record): string => $record->loan->situationLabel())
                    ->badge()
                    ->color(fn (LoanItem $record): string => $record->loan->situationColor()),
            ]);
    }
}
