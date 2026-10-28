<?php

namespace App\Filament\Resources\Maintenance\Tables;

use App\Enums\MaintenanceStatus;
use App\Enums\ProviderType;
use App\Filament\Resources\Maintenance\MaintenanceResource;
use App\Models\MaintenanceJob;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * E22 : liste des interventions.
 */
class MaintenanceJobsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('starts_on', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('asset'))
            ->emptyStateHeading('Aucune intervention')
            ->emptyStateDescription('Les interventions de maintenance et de réparation apparaîtront ici.')
            ->columns([
                TextColumn::make('reference')
                    ->label('N°')
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->color('primary')
                    ->searchable()
                    ->sortable()
                    ->url(fn (MaintenanceJob $record): string => MaintenanceResource::getUrl('view', ['record' => $record])),
                TextColumn::make('asset.number')
                    ->label('Bien')
                    ->fontFamily('mono')
                    ->description(fn (MaintenanceJob $record): ?string => $record->asset?->label())
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'asset',
                        fn (Builder $asset) => $asset->where('number', 'like', "%{$search}%")->orWhere('designation', 'like', "%{$search}%"),
                    )),
                TextColumn::make('nature')->label('Nature')->description(fn (MaintenanceJob $record): string => $record->reason)->wrap(),
                TextColumn::make('starts_on')->label('Début')->date('d/m/Y')->sortable(),
                TextColumn::make('ends_on')->label('Fin')->date('d/m/Y')->placeholder('—')->sortable(),
                TextColumn::make('provider_name')
                    ->label('Prestataire')
                    ->placeholder('—')
                    ->description(fn (MaintenanceJob $record): ?string => $record->provider_type === ProviderType::Internal ? 'Interne' : null)
                    ->searchable(),
                TextColumn::make('cost')->label('Coût')->numeric(thousandsSeparator: ' ')->fontFamily('mono')->alignEnd()->placeholder('—')->sortable(),
                TextColumn::make('status')->label('Statut')->badge(),
                TextColumn::make('result')->label('Résultat')->badge()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Statut')->options(MaintenanceStatus::class),
                SelectFilter::make('provider_type')->label('Intervenant')->options(ProviderType::class),
                SelectFilter::make('year')
                    ->label('Période')
                    ->options(fn (): array => collect(range(today()->year, today()->year - 5))->mapWithKeys(fn (int $year): array => [$year => 'Exercice '.$year])->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, $year) => $query->whereYear('starts_on', (int) $year))),
            ])
            ->recordActions([
                ViewAction::make()->label('Détail'),
            ]);
    }
}
