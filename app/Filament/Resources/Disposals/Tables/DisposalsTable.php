<?php

namespace App\Filament\Resources\Disposals\Tables;

use App\Enums\DisposalAccountingStatus;
use App\Enums\DisposalReason;
use App\Filament\Resources\Disposals\Actions\ValidateDisposalAction;
use App\Models\Disposal;
use App\Support\Money;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * E26 : liste des sorties (recherche, motif, exercice, validation comptable).
 */
class DisposalsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('disposed_on', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('asset'))
            ->searchPlaceholder('N° de sortie, n° ou désignation du bien…')
            ->emptyStateHeading('Aucune sortie du patrimoine')
            ->emptyStateDescription('Un bien quitte le patrimoine par une sortie (cession, don, réforme, destruction, perte, vol) ; il n\'est jamais supprimé.')
            ->columns([
                TextColumn::make('reference')
                    ->label('N°')
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->color('primary')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('disposed_on')
                    ->label('Date')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('asset.number')
                    ->label('Bien')
                    ->fontFamily('mono')
                    ->description(fn (Disposal $record): string => $record->asset->label())
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas('asset', fn (Builder $asset) => $asset->where(fn (Builder $match) => $match
                        ->where('number', 'like', '%'.$search.'%')
                        ->orWhere('designation', 'like', '%'.$search.'%')))),
                TextColumn::make('reason')
                    ->label('Motif')
                    ->badge(),
                TextColumn::make('decided_by')
                    ->label('Décision')
                    ->toggleable(),
                TextColumn::make('original_value')
                    ->label('Valeur d\'origine')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->fontFamily('mono')
                    ->alignEnd(),
                TextColumn::make('accumulated_depreciation')
                    ->label('Amort. cumulés')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->fontFamily('mono')
                    ->alignEnd(),
                TextColumn::make('net_book_value')
                    ->label('VNC sortie')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->fontFamily('mono')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('accounting_status')
                    ->label('Comptabilité')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('reason')->label('Motif')->options(DisposalReason::class),
                SelectFilter::make('year')
                    ->label('Exercice')
                    ->options(fn (): array => Disposal::query()->get(['disposed_on'])->map(fn (Disposal $disposal): int => $disposal->disposed_on->year)->unique()->sortDesc()->mapWithKeys(fn (int $year): array => [$year => (string) $year])->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $query, $year) => $query
                        ->whereDate('disposed_on', '>=', $year.'-01-01')
                        ->whereDate('disposed_on', '<=', $year.'-12-31'))),
                SelectFilter::make('accounting_status')->label('Validation')->options(DisposalAccountingStatus::class),
            ])
            ->filtersFormColumns(3)
            ->recordActions([
                ViewAction::make(),
                ValidateDisposalAction::make(),
            ]);
    }
}
