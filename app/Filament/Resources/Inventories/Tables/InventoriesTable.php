<?php

namespace App\Filament\Resources\Inventories\Tables;

use App\Enums\InventoryScopeType;
use App\Enums\InventoryStatus;
use App\Filament\Resources\Inventories\InventoryResource;
use App\Models\Inventory;
use App\Services\Inventories\InventoryReport;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * E18 : liste des inventaires (référence, périmètre, période, responsable, avancement, écarts, statut).
 * Avancement et écarts comptés sur les lignes du périmètre de l'utilisateur (RG-81).
 */
class InventoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('reference', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('manager'))
            ->searchPlaceholder('Référence…')
            ->emptyStateHeading('Aucun inventaire')
            ->emptyStateDescription('Un inventaire compare la liste des biens à ce qui est réellement sur place.')
            ->columns([
                TextColumn::make('reference')
                    ->label('N°')
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->color('primary')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('scope_type')
                    ->label('Périmètre')
                    ->formatStateUsing(fn (Inventory $record): string => $record->scopeLabel()),
                TextColumn::make('starts_on')
                    ->label('Période')
                    ->formatStateUsing(fn (Inventory $record): string => $record->periodLabel())
                    ->sortable(),
                TextColumn::make('manager.last_name')
                    ->label('Responsable')
                    ->formatStateUsing(fn (Inventory $record): string => $record->manager->fullName()),
                TextColumn::make('progress')
                    ->label('Avancement')
                    ->state(function (Inventory $record): HtmlString {
                        $counters = app(InventoryReport::class)->counters($record);
                        $progress = $record->started_at === null ? 0 : $counters['progress'];

                        return new HtmlString('<div style="display:flex;align-items:center;gap:8px;min-width:150px">'
                            .'<div style="flex:1;height:8px;background:#E9ECF0;border-radius:4px"><div style="height:8px;border-radius:4px;background:#0B5D6B;width:'.$progress.'%"></div></div>'
                            .'<span class="pat-mono" style="font-size:12px">'.$progress.' %</span></div>');
                    })
                    ->html(),
                TextColumn::make('discrepancies')
                    ->label('Écarts')
                    ->state(fn (Inventory $record): string => $record->started_at === null ? '·' : (string) app(InventoryReport::class)->discrepancyCount($record))
                    ->fontFamily('mono')
                    ->alignEnd(),
                TextColumn::make('status')
                    ->label('Statut')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Statut')->options(InventoryStatus::class),
                SelectFilter::make('scope_type')->label('Type de périmètre')->options(InventoryScopeType::class),
            ])
            ->recordUrl(fn (Inventory $record): string => InventoryResource::urlFor($record))
            ->recordActions([
                Action::make('open')
                    ->label(fn (Inventory $record): string => match ($record->status) {
                        InventoryStatus::Planned => 'Modifier',
                        InventoryStatus::InProgress => (auth()->user()?->can('enter', $record) ?? false) ? 'Saisir' : 'Rapport',
                        InventoryStatus::ToValidate => (auth()->user()?->can('close', $record) ?? false) ? 'Valider' : 'Rapport',
                        InventoryStatus::Closed => 'Rapport',
                    })
                    ->visible(fn (Inventory $record): bool => ! $record->isPlanned() || (auth()->user()?->can('update', $record) ?? false))
                    ->url(fn (Inventory $record): string => InventoryResource::urlFor($record)),
            ]);
    }
}
