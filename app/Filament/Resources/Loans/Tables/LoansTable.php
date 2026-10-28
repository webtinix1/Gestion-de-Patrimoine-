<?php

namespace App\Filament\Resources\Loans\Tables;

use App\Enums\LoanDirection;
use App\Filament\Actions\LoanReturnAction;
use App\Filament\Resources\Loans\LoanResource;
use App\Models\Loan;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * E15 : liste des prêts et emprunts (onglets en cours, en retard, emprunts, historique).
 */
class LoansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('out_on', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['items.asset', 'items.conditionOut', 'items.conditionBack']))
            ->emptyStateHeading('Aucun prêt')
            ->emptyStateDescription('Les prêts et les emprunts de matériel apparaîtront ici.')
            ->columns([
                TextColumn::make('reference')
                    ->label('N°')
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->color('primary')
                    ->searchable()
                    ->sortable()
                    ->url(fn (Loan $record): string => LoanResource::getUrl('view', ['record' => $record])),
                TextColumn::make('items')
                    ->label('Bien')
                    ->fontFamily('mono')
                    ->state(fn (Loan $record): string => $record->items->first()->asset->number ?? $record->items->first()->external_reference ?? '—')
                    ->description(fn (Loan $record): ?string => $record->items->count() > 1
                        ? $record->items->first()?->asset?->label().' + '.($record->items->count() - 1).' autre(s)'
                        : ($record->items->first()?->asset?->label() ?? $record->items->first()?->external_designation))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas('items', fn (Builder $items) => $items
                        ->where('external_designation', 'like', "%{$search}%")
                        ->orWhere('external_reference', 'like', "%{$search}%")
                        ->orWhereHas('asset', fn (Builder $asset) => $asset->where('number', 'like', "%{$search}%")->orWhere('designation', 'like', "%{$search}%")))),
                TextColumn::make('borrower_name')
                    ->label('Bénéficiaire / prêteur')
                    ->description(fn (Loan $record): ?string => $record->borrower_contact)
                    ->searchable()
                    ->wrap(),
                TextColumn::make('out_on')->label('Sortie')->date('d/m/Y')->sortable(),
                TextColumn::make('due_on')->label('Retour prévu')->date('d/m/Y')->sortable(),
                TextColumn::make('returned_on')->label('Retour effectif')->date('d/m/Y')->placeholder('—')->sortable(),
                TextColumn::make('condition')
                    ->label('État départ')
                    ->state(fn (Loan $record): ?string => $record->items->first()?->conditionOut?->label),
                TextColumn::make('situation')
                    ->label('Situation')
                    ->state(fn (Loan $record): string => $record->situationLabel())
                    ->badge()
                    ->color(fn (Loan $record): string => $record->situationColor()),
            ])
            ->filters([
                SelectFilter::make('direction')->label('Type')->options(LoanDirection::class),
            ])
            ->recordActions([
                ViewAction::make()->label('Détail'),
                LoanReturnAction::make(),
            ]);
    }
}
