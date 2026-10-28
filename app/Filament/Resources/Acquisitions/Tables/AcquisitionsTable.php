<?php

namespace App\Filament\Resources\Acquisitions\Tables;

use App\Enums\AcquisitionStatus;
use App\Enums\FundingSource;
use App\Filament\Resources\Acquisitions\Actions\ValidateAcquisitionAction;
use App\Models\Acquisition;
use App\Support\Money;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * E11 : liste des acquisitions, filtres de la maquette (recherche, période, site, département, financement).
 */
class AcquisitionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('acquired_on', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['supplier', 'site', 'department'])->withCount('assets'))
            ->searchPlaceholder('N°, fournisseur, référence de facture…')
            ->emptyStateHeading('Aucune acquisition')
            ->emptyStateDescription('Une acquisition regroupe les biens d\'une même facture.')
            ->columns([
                TextColumn::make('reference')
                    ->label('N°')
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->color('primary')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('acquired_on')
                    ->label('Date')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('supplier.name')
                    ->label('Fournisseur')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('invoice_reference')
                    ->label('Facture')
                    ->fontFamily('mono')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('department.name')
                    ->label('Bénéficiaire')
                    ->description(fn (Acquisition $record): string => $record->site->name),
                TextColumn::make('funding_source')
                    ->label('Financement')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('assets_count')
                    ->label('Biens')
                    ->fontFamily('mono')
                    ->alignEnd(),
                TextColumn::make('total_amount')
                    ->label('Montant (FCFA)')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->fontFamily('mono')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('year')
                    ->label('Exercice')
                    ->options(fn (): array => Acquisition::query()->get(['acquired_on'])->map(fn (Acquisition $a): int => $a->acquired_on->year)->unique()->sortDesc()->mapWithKeys(fn (int $year): array => [$year => (string) $year])->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $query, $year) => $query
                        ->whereDate('acquired_on', '>=', $year.'-01-01')
                        ->whereDate('acquired_on', '<=', $year.'-12-31'))),
                Filter::make('period')
                    ->label('Période')
                    ->schema([
                        DatePicker::make('from')->label('Du'),
                        DatePicker::make('until')->label('Au'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->whereDate('acquired_on', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, $date) => $query->whereDate('acquired_on', '<=', $date))),
                SelectFilter::make('site_id')->label('Site')->relationship('site', 'name')->preload(),
                SelectFilter::make('department_id')->label('Département bénéficiaire')->relationship('department', 'name')->preload(),
                SelectFilter::make('funding_source')->label('Financement')->options(FundingSource::class),
                SelectFilter::make('status')->label('Statut')->options(AcquisitionStatus::class),
            ])
            ->filtersFormColumns(3)
            ->recordUrl(fn (Acquisition $record): string => route('filament.admin.resources.acquisitions.view', $record))
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ValidateAcquisitionAction::make(),
            ]);
    }
}
